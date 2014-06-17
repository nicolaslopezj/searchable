<?php namespace Nicolaslopezj\Searchable;

class DBHelper {
    //get the count of rows of a query
    //only tested with the search
    public static function getQueryCount($db_query)
    {
        $query = $db_query['query'];
        $bindings = $db_query['bindings'];

        //we take out the order by and limit, becouse its not necesary
        $query = explode(' order by ', $query)[0];
        $query = explode(' limit ', $query)[0];

        //build the count query
        $count_query = 'select count(*) as count from (' . $query . ') as results';

        //execute the query and get the result
        $count = DB::select(DB::raw($count_query), $bindings)[0]->count;

        return intval($count);
    }
}