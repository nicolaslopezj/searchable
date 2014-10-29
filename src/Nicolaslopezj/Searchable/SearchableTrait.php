<?php namespace Nicolaslopezj\Searchable;

use Illuminate\Database\Query\Expression;
use Config;

/**
 * Trait SearchableTrait
 * @package Nicolaslopezj\Searchable
 */
trait SearchableTrait
{

    protected $search_bindings;

    /**
     * Makes the search process
     *
     * @param $query
     * @param $search
     * @return mixed
     */
    public function scopeSearch($query, $search)
    {
        $query->select($this->getTable() . '.*');
        $this->makeJoins($query);

        if ( ! $search)
        {
            return $query;
        }

        $words = explode(' ', $search);
        $selects = [];
        $this->search_bindings = [];
        $relevance_count = 0;

        foreach ($this->getColumns() as $column => $relevance)
        {
            $relevance_count += $relevance;
            $queries = $this->getSearchQueriesForColumn($query, $column, $relevance, $words);
            foreach ($queries as $select)
            {
                $selects[] = $select;
            }
        }

        $this->addSelectsToQuery($query, $selects);
        $this->filterQueryWithRelevace($query, $selects, ($relevance_count / 4));

        $this->makeGroupBy($query);

        $this->addBindingsToQuery($query, $this->search_bindings);

        return $query;
    }

    /**
     * Returns database driver Ej: mysql, pgsql
     *
     * @return array
     */
    protected function getDatabaseDriver() {
        $key = Config::get('database.default');
        return Config::get('database.connections.' . $key . '.driver');
    }

    /**
     * Returns the search columns
     *
     * @return array
     */
    protected function getColumns()
    {
        return $this->searchable['columns'];
    }

    /**
     * Returns the tables that has to join
     *
     * @return array
     */
    protected function getJoins()
    {
        return array_get($this->searchable, 'joins', []);
    }

    /**
     * Adds the join sql to the query
     *
     * @param $query
     */
    protected function makeJoins(&$query)
    {
        foreach ($this->getJoins() as $table => $keys)
        {
            $query->leftJoin($table, $keys[0], '=', $keys[1]);
        }
    }

    /**
     * Make the query dont repeat the results
     *
     * @param $query
     */
    protected function makeGroupBy(&$query)
    {
        $query->groupBy($this->primaryKey);
    }

    /**
     * Puts all the select clauses to the main query
     *
     * @param $query
     * @param $selects
     */
    protected function addSelectsToQuery(&$query, $selects)
    {
        $selects = new Expression(implode(' + ', $selects) . ' as relevance');
        $query->addSelect($selects);
    }

    /**
     * Adds relevance filter to the query
     *
     * @param $query
     * @param $relevance_count
     */
    protected function filterQueryWithRelevace(&$query, $selects, $relevance_count)
    {
        $comparator = $this->getDatabaseDriver() == 'pgsql' ? implode(' + ', $selects) : 'relevance';
        $query->havingRaw($comparator . ' > ' . $relevance_count);
        $query->orderBy('relevance', 'desc');

        // add bindings to postgres
    }

    /**
     * Returns the search queries for the specified column
     *
     * @param $query
     * @param $column
     * @param $relevance
     * @param $words
     * @return array
     */
    protected function getSearchQueriesForColumn(&$query, $column, $relevance, $words)
    {
        $like_comparator = $this->getDatabaseDriver() == 'pgsql' ? 'ILIKE' : 'LIKE';

        $queries = [];

        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, $like_comparator, 15);
        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, $like_comparator, 5, '', '%');
        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, $like_comparator, 1, '%', '%');

        return $queries;
    }

    /**
     * Returns the sql string for the parameters
     *
     * @param $query
     * @param $column
     * @param $relevance
     * @param $words
     * @param $compare
     * @param $relevance_multiplier
     * @param string $pre_word
     * @param string $post_word
     * @return string
     */
    protected function getSearchQuery(&$query, $column, $relevance, $words, $compare, $relevance_multiplier, $pre_word = '', $post_word = '')
    {
        $fields = [];

        $cases = [];
        foreach ($words as $word)
        {
            $field = $column . " " . $compare . " ?";
            $cases[] = '(case when ' . $field . ' then ' . $relevance * $relevance_multiplier . ' else 0 end)';
            $this->search_bindings[] = $pre_word . $word . $post_word;
        }

        return implode(' + ', $cases);
    }

    protected function addBindingsToQuery(&$query, $bindings) {
        $count = $this->getDatabaseDriver() == 'pgsql' ? 2 : 1;
        for ($i = 0; $i < $count; $i++) {
            foreach($bindings as $binding) {
                $type = $i == 0 ? 'select' : 'having';
                $query->addBinding($binding, $type);
            }
        }
    }

}