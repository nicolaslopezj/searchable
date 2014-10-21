Searchable, a search trait for Laravel
==========================================

Searchable is a trait for Laravel 4.2+ that adds a simple search function to Eloquent Models.

Searchable allows you to perform searches in a table giving priorities to each field for the table and it's relations.

This is not optimized for big searches, but sometimes you just need to make it simple (Although it is not slow).

# Installation

Simply add the package to your `composer.json` file and run `composer update`.

```
"nicolaslopezj/searchable": "1.1.*"
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
        'columns' => [
            'first_name' => 10,
            'last_name' => 10,
            'bio' => 2,
            'email' => 5,
            'posts.title' => 2,
            'posts.body' => 1,
        ],
        'joins' => [
            'posts' => ['users.id','posts.user_id'],
        ],
    ];

    public function posts() {
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
// Search only the active users
$users = User::where('status', 'active')
->search($query)
->paginate(20);
```


# How does it works?

Searchable builds a query that search through your model using Laravel's Eloquent.
Here is an example query

####Eloquent Model:
```php
use Nicolaslopezj\Searchable\SearchableTrait;

class Post extends \Eloquent {

    use SearchableTrait;

    protected $fillable = ['title', 'resume', 'body', 'tags'];
    
    /**
     * Searchable rules.
     *
     * @var array
     */
    protected $searchable = [
        'columns' => [
            'title' => 20,
            'resume' => 5,
            'body' => 2,
        ],
        'joins' => [
            
        ],
    ];

}
```

####Search:
```php
$search = Post::search('Sed neque labore')->get();
```

####Result:
```sql
SELECT *,
-- For each column you specify makes 3 "ifs" containing 
-- each word of the search input and adds relevace to 
-- the row

-- The first checks if the column is equal to the word,
-- if then it adds relevace*15
if(title = 'Sed' || title = 'neque' || title = 'labore', 300, 0) +

-- The second checks if the column starts with the word,
-- if then it adds relevace*5
if(title LIKE 'Sed%' || title LIKE 'neque%' || title LIKE 'labore%', 100, 0) + 

-- The third checks if the column contains the word, 
-- if then it adds relevace*1
if(title LIKE '%Sed%' || title LIKE '%neque%' || title LIKE '%labore%', 20, 0) + 

-- Repeats with each column
if(resume = 'Sed' || resume = 'neque' || resume = 'labore', 75, 0) + 
if(resume LIKE 'Sed%' || resume LIKE 'neque%' || resume LIKE 'labore%', 25, 0) + 
if(resume LIKE '%Sed%' || resume LIKE '%neque%' || resume LIKE '%labore%', 5, 0) + 

if(body = 'Sed' || body = 'neque' || body = 'labore', 30, 0) + 
if(body LIKE 'Sed%' || body LIKE 'neque%' || body LIKE 'labore%', 10, 0) + 
if(body LIKE '%Sed%' || body LIKE '%neque%' || body LIKE '%labore%', 2, 0) + 

AS relevance
FROM `posts`

-- Selects only the rows that have more than
-- the sum of all attributes relevances and divided by 4
-- Ej: (20 + 5 + 2) / 4 = 6.75
HAVING relevance > 6.75

-- Orders the results by relevance
ORDER BY `relevance` DESC
```

