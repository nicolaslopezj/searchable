Searchable, a search trait for Laravel
==========================================

Searchable is a trait for Laravel 4.2+ and Laravel 5.0 that adds a simple search function to Eloquent Models.

Searchable allows you to perform searches in a table giving priorities to each field for the table and it's relations.

This is not optimized for big searches, but sometimes you just need to make it simple (Although it is not slow).

# Installation

Simply add the package to your `composer.json` file and run `composer update`.

```
"nicolaslopezj/searchable": "1.*"
```

# Usage

Add the trait to your model and your search rules.

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
        /**
         * Columns and their priority in search results.
         * Columns with higher values are more important.
         * Columns with equal values have equal importance.
         *
         * @var array
         */
        'columns' => [
            'users.first_name' => 10,
            'users.last_name' => 10,
            'users.bio' => 2,
            'users.email' => 5,
            'posts.title' => 2,
            'posts.body' => 1,
        ],
        'joins' => [
            'posts' => ['users.id','posts.user_id'],
        ],
    ];

    public function posts()
    {
        return $this->hasMany('Post');
    }

}
```

Now you can search your model.

```php
// Simple search
$users = User::search($query)->get();

// Search and get relations
// It will not get the relations if you don't do this
$users = User::search($query)
            ->with('posts')
            ->get();
```


## Search Paginated

As easy as laravel default queries

```php
// Search with relations and paginate
$users = User::search($query)
            ->with('posts')
            ->paginate(20);
```

## Mix queries

Search method is compatible with any eloquent method. You can do things like this:

```php
// Search only active users
$users = User::where('status', 'active')
            ->search($query)
            ->paginate(20);
```

## Custom Threshold

The default threshold for accepted relevance is the sum of all attribute relevance divided by 4.
To change this value you can pass in a second parameter to search() like so:

```php
// Search with lower relevance threshold
$users = User::where('status', 'active')
            ->search($query, 0)
            ->paginate(20);
```

The above, will return all users in order of relevance.

## Entire Text search

By default, multi-word search terms are split and Searchable searches for each word individually. Relevance plays a role in prioritizing matches that matched on multiple words. If you want to prioritize matches that include the multi-word search (thus, without splitting into words) you can enable full text search by setting the third value to true. Example:

```php
// Prioritize matches containing "John Doe" above matches containing only "John" or "Doe".
$users = User::search("John Doe", null, true)->get();
```

If you explicitly want to search for full text matches only, you can disable multi-word splitting by setting the fourth parameter to true.

```php
// Do not include matches that only matched "John" OR "Doe".
$users = User::search("John Doe", null, true, true)->get();
```

# How does it work?

Searchable builds a query that search through your model using Laravel's Eloquent.
Here is an example query

####Eloquent Model:
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
        'columns' => [
            'first_name' => 10,
            'last_name' => 10,
            'bio' => 2,
            'email' => 5,
        ],
    ];

}
```

####Search:
```php
$search = User::search('Sed neque labore', null, true)->get();
```

####Result:
```sql
select `users`.*, 

-- If third parameter is set as true, it will check if the column starts with the search
-- if then it adds relevance * 30
-- this ensures that relevant results will be at top
(case when first_name LIKE 'Sed neque labore%' then 300 else 0 end) + 

-- For each column you specify makes 3 "ifs" containing 
-- each word of the search input and adds relevace to 
-- the row

-- The first checks if the column is equal to the word,
-- if then it adds relevance * 15
(case when first_name LIKE 'Sed' || first_name LIKE 'neque' || first_name LIKE 'labore' then 150 else 0 end) + 

-- The second checks if the column starts with the word,
-- if then it adds relevance * 5
(case when first_name LIKE 'Sed%' || first_name LIKE 'neque%' || first_name LIKE 'labore%' then 50 else 0 end) + 

-- The third checks if the column contains the word, 
-- if then it adds relevance * 1
(case when first_name LIKE '%Sed%' || first_name LIKE '%neque%' || first_name LIKE '%labore%' then 10 else 0 end) + 

-- Repeats with each column
(case when last_name LIKE 'Sed' || last_name LIKE 'neque' || last_name LIKE 'labore' then 150 else 0 end) + 
(case when last_name LIKE 'Sed%' || last_name LIKE 'neque%' || last_name LIKE 'labore%' then 50 else 0 end) +
(case when last_name LIKE '%Sed%' || last_name LIKE '%neque%' || last_name LIKE '%labore%' then 10 else 0 end) + 

(case when bio LIKE 'Sed' || bio LIKE 'neque' || bio LIKE 'labore' then 30 else 0 end) + 
(case when bio LIKE 'Sed%' || bio LIKE 'neque%' || bio LIKE 'labore%' then 10 else 0 end) + 
(case when bio LIKE '%Sed%' || bio LIKE '%neque%' || bio LIKE '%labore%' then 2 else 0 end) + 

(case when email LIKE 'Sed' || email LIKE 'neque' || email LIKE 'labore' then 75 else 0 end) + 
(case when email LIKE 'Sed%' || email LIKE 'neque%' || email LIKE 'labore%' then 25 else 0 end) + 
(case when email LIKE '%Sed%' || email LIKE '%neque%' || email LIKE '%labore%' then 5 else 0 end) 

as relevance 
from `users` 
group by `id` 

-- Selects only the rows that have more than
-- the sum of all attributes relevances and divided by 4
-- Ej: (20 + 5 + 2) / 4 = 6.75
having relevance > 6.75 

-- Orders the results by relevance
order by `relevance` desc
```

## Contributing

Anyone is welcome to contribute. Fork, make your changes, and then submit a pull request.

[![Support via Gittip](https://rawgithub.com/twolfson/gittip-badge/0.2.0/dist/gittip.png)](https://gratipay.com/nicolaslopezj/)
