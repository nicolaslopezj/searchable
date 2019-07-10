<?php namespace Nicolaslopezj\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trait SearchableTrait
 * @package Nicolaslopezj\Searchable
 * @property array $searchable
 * @property string $table
 * @property string $primaryKey
 * @method string getTable()
 */
trait SearchableTrait
{
    /**
     * @var array
     */
    protected $search_bindings = [];

    /**
     * Creates the search scope.
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @param string $search
     * @param float|null $threshold
     * @param  boolean $entireText
     * @param  boolean $entireTextOnly
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch(Builder $q, $search, $threshold = null, $entireText = false, $entireTextOnly = false)
    {
        return $this->scopeSearchRestricted($q, $search, null, $threshold, $entireText, $entireTextOnly);
    }

    public function scopeSearchRestricted(Builder $q, $search, $restriction, $threshold = null, $entireText = false, $entireTextOnly = false)
    {
        $query = clone $q;
        $query->select($this->getTable() . '.*');
        $this->makeJoins($query);

       if ($search === false)
        {
            return $q;
        }

        $search = mb_strtolower(trim($search));
        preg_match_all('/(?:")((?:\\\\.|[^\\\\"])*)(?:")|(\S+)/', $search, $matches);
        $words = $matches[1];
        for ($i = 2; $i < count($matches); $i++) {
            $words = array_filter($words) + $matches[$i];
        }

        $selects = [];
        $this->search_bindings = [];
        $relevance_count = 0;

        foreach ($this->getColumns() as $column => $relevance)
        {
            $relevance_count += $relevance;

            if (!$entireTextOnly) {
                $queries = $this->getSearchQueriesForColumn($query, $column, $relevance, $words);
            } else {
                $queries = [];
            }

            if ( ($entireText === true && count($words) > 1) || $entireTextOnly === true )
            {
                $queries[] = $this->getSearchQuery($query, $column, $relevance, [$search], 50, '', '');
                $queries[] = $this->getSearchQuery($query, $column, $relevance, [$search], 30, '%', '%');
            }

            foreach ($queries as $select)
            {
                if (!empty($select)) {
                    $selects[] = $select;
                }
            }
        }

        $this->addSelectsToQuery($query, $selects);

        // Default the threshold if no value was passed.
        if (is_null($threshold)) {
            $threshold = $relevance_count / count($this->getColumns());
        }

        if (!empty($selects)) {
            $this->filterQueryWithRelevance($query, $selects, $threshold);
        }

        $this->makeGroupBy($query);

        if(is_callable($restriction)) {
            $query = $restriction($query);
        }

        $this->mergeQueries($query, $q);

        return $q;
    }

    /**
     * Returns database driver Ex: mysql, pgsql, sqlite.
     *
     * @return array
     */
    protected function getDatabaseDriver() {
        $key = $this->connection ?: Config::get('database.default');
        return Config::get('database.connections.' . $key . '.driver');
    }

    /**
     * Returns the search columns.
     *
     * @return array
     */
    protected function getColumns()
    {
        if (array_key_exists('columns', $this->searchable)) {
            $driver = $this->getDatabaseDriver();
            $prefix = Config::get("database.connections.$driver.prefix");
            $columns = [];
            foreach($this->searchable['columns'] as $column => $priority){
                $columns[$prefix . $column] = $priority;
            }
            return $columns;
        } else {
            return DB::connection()->getSchemaBuilder()->getColumnListing($this->table);
        }
    }

    /**
     * Returns whether or not to keep duplicates.
     *
     * @return array
     */
    protected function getGroupBy()
    {
        if (array_key_exists('groupBy', $this->searchable)) {
            return $this->searchable['groupBy'];
        }

        return false;
    }

    /**
     * Returns the table columns.
     *
     * @return array
     */
    public function getTableColumns()
    {
        return $this->searchable['table_columns'];
    }

    /**
     * Returns the tables that are to be joined.
     *
     * @return array
     */
    protected function getJoins()
    {
        return Arr::get($this->searchable, 'joins', []);
    }

    /**
     * Adds the sql joins to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function makeJoins(Builder $query)
    {
        foreach ($this->getJoins() as $table => $keys) {
            $query->leftJoin($table, function ($join) use ($keys) {
                $join->on($keys[0], '=', $keys[1]);
                if (array_key_exists(2, $keys) && array_key_exists(3, $keys)) {
                    $join->whereRaw($keys[2] . ' = "' . $keys[3] . '"');
                }
            });
        }
    }

    /**
     * Makes the query not repeat the results.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function makeGroupBy(Builder $query)
    {
        if ($groupBy = $this->getGroupBy()) {
            $query->groupBy($groupBy);
        } else {
            $driver = $this->getDatabaseDriver();

            if ($driver == 'sqlsrv') {
                $columns = $this->getTableColumns();
            } else {
                $columns = $this->getTable() . '.' .$this->primaryKey;
            }

            $query->groupBy($columns);

            $joins = array_keys(($this->getJoins()));

            foreach ($this->getColumns() as $column => $relevance) {
                array_map(function ($join) use ($column, $query) {
                    if (Str::contains($column, $join)) {
                        $query->groupBy($column);
                    }
                }, $joins);
            }
        }
    }

    /**
     * Puts all the select clauses to the main query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $selects
     */
    protected function addSelectsToQuery(Builder $query, array $selects)
    {
        if (!empty($selects)) {
            $query->selectRaw('max(' . implode(' + ', $selects) . ') as ' . $this->getRelevanceField(), $this->search_bindings);
        }
    }

    /**
     * Adds the relevance filter to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $selects
     * @param float $relevance_count
     */
    protected function filterQueryWithRelevance(Builder $query, array $selects, $relevance_count)
    {
        $comparator = $this->getDatabaseDriver() != 'mysql' ? implode(' + ', $selects) : $this->getRelevanceField();

        $relevance_count=number_format($relevance_count,2,'.','');

        if ($this->getDatabaseDriver() == 'mysql') {
            $bindings = [];
        } else {
            $bindings = $this->search_bindings;
        }
        $query->havingRaw("$comparator >= $relevance_count", $bindings);
        $query->orderBy($this->getRelevanceField(), 'desc');

        // add bindings to postgres
    }

    /**
     * Returns the search queries for the specified column.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column
     * @param float $relevance
     * @param array $words
     * @return array
     */
    protected function getSearchQueriesForColumn(Builder $query, $column, $relevance, array $words)
    {
        $queries = [];

        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, 15);
        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, 5, '', '%');
        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, 1, '%', '%');

        return $queries;
    }

    /**
     * Returns the sql string for the given parameters.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column
     * @param string $relevance
     * @param array $words
     * @param string $compare
     * @param float $relevance_multiplier
     * @param string $pre_word
     * @param string $post_word
     * @return string
     */
    protected function getSearchQuery(Builder $query, $column, $relevance, array $words, $relevance_multiplier, $pre_word = '', $post_word = '')
    {
        $like_comparator = $this->getDatabaseDriver() == 'pgsql' ? 'ILIKE' : 'LIKE';
        $cases = [];

        foreach ($words as $word)
        {
            $cases[] = $this->getCaseCompare($column, $like_comparator, $relevance * $relevance_multiplier);
            $this->search_bindings[] = $pre_word . $word . $post_word;
        }

        return implode(' + ', $cases);
    }

    /**
     * Returns the comparison string.
     *
     * @param string $column
     * @param string $compare
     * @param float $relevance
     * @return string
     */
    protected function getCaseCompare($column, $compare, $relevance) {
        if($this->getDatabaseDriver() == 'pgsql') {
            $field = "LOWER(" . $column . ") " . $compare . " ?";
            return '(case when ' . $field . ' then ' . $relevance . ' else 0 end)';
        }

        $column = str_replace('.', '`.`', $column);
        $field = "LOWER(`" . $column . "`) " . $compare . " ?";
        return '(case when ' . $field . ' then ' . $relevance . ' else 0 end)';
    }

    /**
     * Merge our cloned query builder with the original one.
     *
     * @param \Illuminate\Database\Eloquent\Builder $clone
     * @param \Illuminate\Database\Eloquent\Builder $original
     */
    protected function mergeQueries(Builder $clone, Builder $original) {
        $tableName = DB::connection($this->connection)->getTablePrefix() . $this->getTable();
        if ($this->getDatabaseDriver() == 'pgsql') {
            $original->from(DB::connection($this->connection)->raw("({$clone->toSql()}) as {$tableName}"));
        } else {
            $original->from(DB::connection($this->connection)->raw("({$clone->toSql()}) as `{$tableName}`"));
        }

        // First create a new array merging bindings
        $mergedBindings = array_merge_recursive(
            $clone->getBindings(),
            $original->getBindings()
        );

        // Then apply bindings WITHOUT global scopes which are already included. If not, there is a strange behaviour
        // with some scope's bindings remaning
        $original->withoutGlobalScopes()->setBindings($mergedBindings);
    }

    /**
     * Returns the relevance field name, alias of ratio column in the query.
     *
     * @return string
     */
    protected function getRelevanceField()
    {
        if (isset($this->relevanceField) && !empty($this->relevanceField)) {
            return $this->relevanceField;
        }

        // If property $this->relevanceField is not setted, return the default
        return 'relevance';
    }
}
