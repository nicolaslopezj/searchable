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
     * @param $query
     * @param $search
     * @return mixed
     */
    public function scopeSearch($query, $search) {

        if (!$search) {
            return $query;
        }

        $relevance_count = 0;
        $words = explode(' ', $search);

        $selects = [];
        foreach ($this->getColumns() as $column => $relevance) {
            $relevance_count += $relevance;
            $queries = $this->getSearchQueriesForColumn($column, $relevance, $words);
            foreach ($queries as $select) {
                $selects[] = $select;
            }
        }

        $this->addSelectsToQuery($query, $selects);
        $this->filterQueryWithRelevace($query, ($relevance_count / 4));

        $this->makeJoins($query);

        return $query;
    }

    /**
     * Returns the search columns
     * @return array
     */
    protected function getColumns() {
        return $this->searchable['columns'];
    }

    /**
     * Returns the tables that has to join
     * @return array
     */
    protected function getJoins() {
        return $this->searchable['joins'];
    }

    /**
     * Adds the join sql to the query
     * @param $query
     */
    protected function makeJoins(&$query) {
        foreach ($this->getJoins() as $table => $keys) {
            $query->join($table, $keys[0], '=', $keys[1]);
        }
    }

    /**
     * Puts all the select clauses to the main query
     * @param $query
     * @param $selects
     */
    protected function addSelectsToQuery(&$query, $selects) {
        $selects = new Expression(join(' + ', $selects) . ' as relevance');
        $query->select([$this->getTable() . '.*', $selects]);
    }

    /**
     * Adds relevance filter to the query
     * @param $query
     * @param $relevance_count
     */
    protected function filterQueryWithRelevace(&$query, $relevance_count) {
        $query->havingRaw('relevance > ' . $relevance_count);
        $query->orderBy('relevance', 'desc');
    }

    /**
     * Returns the search queries for the specified column
     * @param $column
     * @param $relevance
     * @param $words
     * @return array
     */
    protected function getSearchQueriesForColumn($column, $relevance, $words) {
        $queries = [];
        $queries[] = $this->getSearchQuery($column, $relevance, $words, '=', 15);
        $queries[] = $this->getSearchQuery($column, $relevance, $words, 'LIKE', 5, '', '%');
        $queries[] = $this->getSearchQuery($column, $relevance, $words, 'LIKE', 1, '%', '%');
        return $queries;
    }

    /**
     * Returns the sql string for the parameters
     * @param $column
     * @param $relevance
     * @param $words
     * @param $compare
     * @param $relevance_multiplier
     * @param string $pre_word
     * @param string $post_word
     * @return string
     */
    protected function getSearchQuery($column, $relevance, $words, $compare, $relevance_multiplier, $pre_word = '', $post_word = '') {
        $fields = [];
        foreach ($words as $word) {
            $fields[] = $column . " " . $compare . " '" . $pre_word . $word . $post_word . "'";
        }

        $fields = join(' || ', $fields);

        return 'if(' . $fields . ', ' . $relevance * $relevance_multiplier . ', 0)';
    }

}