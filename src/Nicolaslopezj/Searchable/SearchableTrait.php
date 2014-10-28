<?php namespace Nicolaslopezj\Searchable;

use Illuminate\Database\Query\Expression;

/**
 * Trait SearchableTrait
 * @package Nicolaslopezj\Searchable
 */
trait SearchableTrait
{
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
        $relevance_count = 0;

        foreach ($this->getColumns() as $column => $relevance)
        {
            $relevance_count += $relevance;

            $queries = $this->getSearchQueriesForColumn($column, $relevance, $words);

            foreach ($queries as $select)
            {
                $selects[] = $select;
            }
        }

        $this->addSelectsToQuery($query, $selects);
        $this->makeGroupBy($query);

        $this->makeSubquery($query);

        $this->filterQueryWithRelevance($query, ($relevance_count / 4));

        return $query;
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
     * Wraps the query with a subquery
     *
     * @param $query
     */
    protected function makeSubquery(&$query)
    {
        $query = \DB::table(null)->selectRaw('* from(' . $query->toSql() . ') as q');
    }

    /**
     * Adds relevance filter to the query
     *
     * @param $query
     * @param $relevance_count
     */
    protected function filterQueryWithRelevance(&$query, $relevance_count)
    {
        $query->where('relevance', '>', intval($relevance_count));
        $query->orderBy('relevance', 'desc');
    }

    /**
     * Returns the search queries for the specified column
     *
     * @param $column
     * @param $relevance
     * @param $words
     * @return array
     */
    protected function getSearchQueriesForColumn($column, $relevance, $words)
    {
        $queries = [];

        $queries[] = $this->getSearchQuery($column, $relevance, $words, '=', 15);
        $queries[] = $this->getSearchQuery($column, $relevance, $words, 'LIKE', 5, '', '%');
        $queries[] = $this->getSearchQuery($column, $relevance, $words, 'LIKE', 1, '%', '%');

        return $queries;
    }

    /**
     * Returns the sql string for the parameters
     *
     * @param $column
     * @param $relevance
     * @param $words
     * @param $compare
     * @param $relevance_multiplier
     * @param string $pre_word
     * @param string $post_word
     * @return string
     */
    protected function getSearchQuery($column, $relevance, $words, $compare, $relevance_multiplier, $pre_word = '', $post_word = '')
    {
        $fields = [];

        foreach ($words as $word)
        {
            $fields[] = $column . " " . $compare . " '" . $pre_word . $word . $post_word . "'";
        }

        $fields = implode(' || ', $fields);

        return 'case when ' . $fields . ' then ' . $relevance * $relevance_multiplier . ' else 0 end';
    }
}
