Searchable, a search trait for Laravel
==========================================

Searchable is a trait for Laravel 4.2+ that adds a simple search function to Eloquent Models.

Searchable allows custom columns and relevance for each model.

# Installation

Simply add the package to your `composer.json` file and run `composer update`.

```
"nicolaslopezj/searchable": "0.1.*"
```

## Overview

First, add the trait to your model and add your search rules.

```php
use Nicolaslopezj\Searchable\SearchableTrait;

class User extends \Eloquent
{
	use SearchableTrait;

    /**
     * Searchable rules.
     *
     * @var array
     */
    protected $searchable = [
        ['column' => 'first_name', 'relevance' => 10],
        ['column' => 'last_name', 'relevance' => 10],
        ['column' => 'bio', 'relevance' => 2],
        ['column' => 'email', 'relevance' => 5],
    ];
}
```

Now you can search your model in a very simple way

```php
// Simple search
$users = User::search($query)->get();

// Search and get relations
$users = User::search($query)
->with('photos')
->get();
```


### Search Paginated

If you are going to search the model and use pagination, you have to do this

```php
// This class is required
use Nicolaslopezj\Searchable\DBHelper;
```

```php
// Get the current page values
$page = $page ? $page : 1;
$count = $count ? $count : 20; // items per page
$from = 1 + $count * ($page - 1);

// Perform the search
$data = User::search($query)
->take($count)
->skip($from - 1)
->get()
->toArray();

// Get the count of items
$db_query = end(DB::getQueryLog());
$total_items = DBHelper::getQueryCount($db_query);

// Create the paginator
$users = Paginator::make($data, $total_items, $count);
```