<?php namespace Analogue\ORM\System;

use Closure;
use Analogue\ORM\EntityCollection;
use Analogue\ORM\Relationships\Relationship;
use Analogue\ORM\Exceptions\EntityNotFoundException;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Analogue Query builder.
 * 
 * Forked from Eloquent Query Builder by Taylor Otwell
 * 
 */
class Query {

	/**
	 * Mapper Instance
	 * 
	 * @var \Analogue\ORM\Mapper
	 */
	protected $mapper;

	/**
	 * Query Builder Instance
	 * @var Illuminate\Database\Query\Builder
	 */
	protected $query;

	/**
	 * Entity Map Instance
	 * @var Analogue\ORM\EntityMapInterface
	 */
	protected $entityMap;
	
	/**
	 * The relationships that should be eager loaded.
	 *
	 * @var array
	 */
	protected $eagerLoad = [];

	/**
	 * All of the registered builder macros.
	 *
	 * @var array
	 */
	protected $macros = [];

	/**
	 * The methods that should be returned from query builder.
	 *
	 * @var array
	 */
	protected $passthru = [
		'toSql', 'lists', 'pluck', 'count',
		'min', 'max', 'avg', 'sum', 'exists', 'getBindings',
	];

	/**
	 * Query Builder Blacklist
	 */
	protected $blacklist = [
		'insert', 'insertGetId', 'lock', 'lockForUpdate', 
		'sharedLock', 'update', 'increment', 'decrement', 'delete',
		'truncate', 'raw', 
	];

	/**
	 * Create a new Mapper query builder instance.
	 *
	 * @param \Illuminate\Database\Query\Builder  $query
	 * @param \Analogue\ORM\Mapper $mapper
	 * @return void
	 */
	public function __construct(QueryBuilder $query, Mapper $mapper)
	{
		$this->mapper = $mapper;

		$this->entityMap = $mapper->getEntityMap();

		// Specify the table to work on
		$this->query = $query->from($this->entityMap->getTable());

		$this->with($this->entityMap->getEagerloadedRelationships() );
	}

	/**
	 * [get description]
	 * @param  array  $columns [description]
	 * @return [type]          [description]
	 */
	public function get($columns = array('*'))
	{
		$entities = $this->getEntities($columns);

		// If we actually found models we will also eager load any relationships that
		// have been specified as needing to be eager loaded, which will solve the
		// n+1 query issue for the developers to avoid running a lot of queries.
		
		if (count($entities) > 0)
		{
			$entities = $this->eagerLoadRelations($entities);
		}

		return $this->entityMap->newCollection($entities);	
	}

	protected function cacheResults(array $entities)
	{
		$keyName = $this->entityMap->getKeyName();

		$cache = $this->mapper->getEntityCache();

		foreach($entities as $entity)
		{
			$cache->set($entity->$keyName, $entity->getEntityAttributes() );
		}
	}
	
	/**
	 * Find an entity by its primary key
	 * 
	 * @param  [type] $id      [description]
	 * @param  array  $columns [description]
	 * @return [type]          [description]
	 */
	public function find($id, $columns = array('*'))
	{
		if (is_array($id))
		{
			return $this->findMany($id, $columns);
		}

		$this->query->where($this->entityMap->getQualifiedKeyName(), '=', $id);

		return $this->first($columns);
	}

	/**
	 * Find a entity by its primary key.
	 *
	 * @param  array  $id
	 * @param  array  $columns
	 * @return EntityCollection|static
	 */
	public function findMany($id, $columns = array('*'))
	{
		if (empty($id)) return new EntityCollection;

		$this->query->whereIn($this->entityMap->getQualifiedKeyName(), $id);

		return $this->get($columns);
	}

	/**
	 * Find a model by its primary key or throw an exception.
	 *
	 * @param  mixed  $id
	 * @param  array  $columns
	 * @return mixed|static
	 *
	 * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
	 */
	public function findOrFail($id, $columns = array('*'))
	{
		if ( ! is_null($entity = $this->find($id, $columns))) return $entity;

		throw (new EntityNotFoundException)->setEntity(get_class($this->entityMap));
	}


	/**
	 * Execute the query and get the first result.
	 *
	 * @param  array  $columns
	 * @return \Analogue\ORM\Entity|static|null
	 */
	public function first($columns = array('*'))
	{
		return $this->take(1)->get($columns)->first();
	}

	/**
	 * Execute the query and get the first result or throw an exception.
	 *
	 * @param  array  $columns
	 * @return \Illuminate\Database\Eloquent\Model|static
	 *
	 * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
	 */
	public function firstOrFail($columns = array('*'))
	{
		if ( ! is_null($entity = $this->first($columns))) return $entity;

		throw (new EntityNotFoundException)->setEntity(get_class($this->entityMap));
	}

	/**
	 * Pluck a single column from the database.
	 *
	 * @param  string  $column
	 * @return mixed
	 */
	public function pluck($column)
	{
		$result = $this->first(array($column));

		if ($result) return $result->{$column};
	}

	/**
	 * Chunk the results of the query.
	 *
	 * @param  int  $count
	 * @param  callable  $callback
	 * @return void
	 */
	public function chunk($count, callable $callback)
	{
		$results = $this->forPage($page = 1, $count)->get();

		while (count($results) > 0)
		{
			// On each chunk result set, we will pass them to the callback and then let the
			// developer take care of everything within the callback, which allows us to
			// keep the memory low for spinning through large result sets for working.
			call_user_func($callback, $results);

			$page++;

			$results = $this->forPage($page, $count)->get();
		}
	}


	
	/**
	 * Get an array with the values of a given column.
	 *
	 * @param  string  $column
	 * @param  string  $key
	 * @return array
	 */
	public function lists($column, $key = null)
	{
		$results = $this->query->lists($column, $key);

		// If the model has a mutator for the requested column, we will spin through
		// the results and mutate the values so that the mutated version of these
		// columns are returned as you would expect from these Eloquent models.
		if ($this->model->hasGetMutator($column))
		{
			foreach ($results as $key => &$value)
			{
				$fill = array($column => $value);

				$value = $this->model->newFromBuilder($fill)->$column;
			}
		}

		return $results;
	}


	/**
	 * Get a paginator for the "select" statement.
	 *
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return \Illuminate\Pagination\Paginator
	 */
	public function paginate($perPage = null, $columns = array('*'))
	{
		$perPage = $perPage ?: $this->entityMap->getPerPage();

		$paginator = $this->query->getConnection()->getPaginator();

		if (isset($this->query->groups))
		{
			return $this->groupedPaginate($paginator, $perPage, $columns);
		}

		return $this->ungroupedPaginate($paginator, $perPage, $columns);
	}

	/**
	 * Get a paginator for a grouped statement.
	 *
	 * @param  \Illuminate\Pagination\Factory  $paginator
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return \Illuminate\Pagination\Paginator
	 */
	protected function groupedPaginate($paginator, $perPage, $columns)
	{
		$results = $this->get($columns)->all();

		return $this->query->buildRawPaginator($paginator, $results, $perPage);
	}


	/**
	 * Get a paginator for an ungrouped statement.
	 *
	 * @param  \Illuminate\Pagination\Factory  $paginator
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return \Illuminate\Pagination\Paginator
	 */
	protected function ungroupedPaginate($paginator, $perPage, $columns)
	{
		$total = $this->query->getPaginationCount();

		// Once we have the paginator we need to set the limit and offset values for
		// the query so we can get the properly paginated items. Once we have an
		// array of items we can create the paginator instances for the items.
		$page = $paginator->getCurrentPage($total);

		$this->query->forPage($page, $perPage);

		return $paginator->make($this->get($columns)->all(), $total, $perPage);
	}

	/**
	 * Get a paginator only supporting simple next and previous links.
	 *
	 * This is more efficient on larger data-sets, etc.
	 *
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return \Illuminate\Pagination\Paginator
	 */
	public function simplePaginate($perPage = null, $columns = array('*'))
	{
		$paginator = $this->query->getConnection()->getPaginator();

		$page = $paginator->getCurrentPage();

		$perPage = $perPage ?: $this->entityMapper->getPerPage();

		$this->query->skip(($page - 1) * $perPage)->take($perPage + 1);

		return $paginator->make($this->get($columns)->all(), $perPage);
	}

/**
	 * Add a basic where clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $operator
	 * @param  mixed   $value
	 * @param  string  $boolean
	 * @return $this
	 */
	public function where($column, $operator = null, $value = null, $boolean = 'and')
	{
		if ($column instanceof Closure)
		{
			$query = $this->newQueryWithoutScopes();

			call_user_func($column, $query);

			$this->query->addNestedWhereQuery($query->getQuery(), $boolean);
		}
		else
		{
			call_user_func_array(array($this->query, 'where'), func_get_args());
		}

		return $this;
	}

	/**
	 * Add an "or where" clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $operator
	 * @param  mixed   $value
	 * @return \Illuminate\Database\Eloquent\Builder|static
	 */
	public function orWhere($column, $operator = null, $value = null)
	{
		return $this->where($column, $operator, $value, 'or');
	}

	/**
	 * Add a relationship count condition to the query.
	 *
	 * @param  string  $relation
	 * @param  string  $operator
	 * @param  int     $count
	 * @param  string  $boolean
	 * @param  \Closure  $callback
	 * @return \Illuminate\Database\Eloquent\Builder|static
	 */
	public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', $callback = null)
	{
		$entity = $this->mapper->newInstance();

		$relation = $this->getHasRelationQuery($relation, $entity);

		$query = $relation->getRelationCountQuery($relation->getRelatedMapper()->getQuery(), $this);

		if ($callback) call_user_func($callback, $query);

		return $this->addHasWhere($query, $relation, $operator, $count, $boolean);
	}

	/**
	 * Add a relationship count condition to the query with where clauses.
	 *
	 * @param  string  $relation
	 * @param  \Closure  $callback
	 * @param  string  $operator
	 * @param  int     $count
	 * @return \Analogue\ORM\System\Query|static
	 */
	public function whereHas($relation, Closure $callback, $operator = '>=', $count = 1)
	{
		return $this->has($relation, $operator, $count, 'and', $callback);
	}

	/**
	 * Add a relationship count condition to the query with an "or".
	 *
	 * @param  string  $relation
	 * @param  string  $operator
	 * @param  int     $count
	 * @return \Analogue\ORM\System\Query|static
	 */
	public function orHas($relation, $operator = '>=', $count = 1)
	{
		return $this->has($relation, $operator, $count, 'or');
	}

	/**
	 * Add a relationship count condition to the query with where clauses and an "or".
	 *
	 * @param  string  $relation
	 * @param  \Closure  $callback
	 * @param  string  $operator
	 * @param  int     $count
	 * @return \Analogue\ORM\System\Query|static
	 */
	public function orWhereHas($relation, Closure $callback, $operator = '>=', $count = 1)
	{
		return $this->has($relation, $operator, $count, 'or', $callback);
	}

	/**
	 * Add the "has" condition where clause to the query.
	 *
	 * @param  \Analogue\ORM\System\Query  $hasQuery
	 * @param  \Analogue\ORM\Relationships\Relationship  $relation
	 * @param  string  $operator
	 * @param  int  $count
	 * @param  string  $boolean
	 * @return \Analogue\ORM\System\Query
	 */
	protected function addHasWhere(Query $hasQuery, Relationship $relation, $operator, $count, $boolean)
	{
		$this->mergeWheresToHas($hasQuery, $relation);

		if (is_numeric($count))
		{
			$count = new Expression($count);
		}

		return $this->where(new Expression('('.$hasQuery->toSql().')'), $operator, $count, $boolean);
	}

	/**
	 * Merge the "wheres" from a relation query to a has query.
	 *
	 * @param  \Analogue\ORM\System\Query  $hasQuery
	 * @param  \Analogue\ORM\Relationships\Relationship  $relation
	 * @return void
	 */
	protected function mergeWheresToHas(Query $hasQuery, Relationship $relation)
	{
		// Here we have the "has" query and the original relation. We need to copy over any
		// where clauses the developer may have put in the relationship function over to
		// the has query, and then copy the bindings from the "has" query to the main.
		$relationQuery = $relation->getBaseQuery();

		$hasQuery->mergeWheres(
			$relationQuery->wheres, $relationQuery->getBindings()
		);

		$this->query->mergeBindings($hasQuery->getQuery());
	}

	/**
	 * Get the "has relation" base query instance.
	 *
	 * @param  string  $relation
	 * @return \Analogue\ORM\System\Query
	 */
	protected function getHasRelationQuery($relation,$entity)
	{
		return Relationship::noConstraints(function() use ($relation,$entity)
		{
			return $this->entityMap->$relation($entity);
		});
	}

	/**
	 * Get the table for the current query object
	 * 
	 * @return string
	 */
	public function getTable()
	{
		return $this->entityMap->getTable();
	}

	/**
	 * Set the relationships that should be eager loaded.
	 *
	 * @param  mixed  $relations
	 * @return $this
	 */
	public function with($relations)
	{
		if (is_string($relations)) $relations = func_get_args();

		$eagers = $this->parseRelations($relations);

		$this->eagerLoad = array_merge($this->eagerLoad, $eagers);

		return $this;
	}

	/**
	 * Parse a list of relations into individuals.
	 *
	 * @param  array  $relations
	 * @return array
	 */
	protected function parseRelations(array $relations)
	{
		$results = array();

		foreach ($relations as $name => $constraints)
		{
			// If the "relation" value is actually a numeric key, we can assume that no
			// constraints have been specified for the eager load and we'll just put
			// an empty Closure with the loader so that we can treat all the same.
			if (is_numeric($name))
			{
				$f = function() {};

				list($name, $constraints) = array($constraints, $f);
			}

			// We need to separate out any nested includes. Which allows the developers
			// to load deep relationships using "dots" without stating each level of
			// the relationship with its own key in the array of eager load names.
			$results = $this->parseNested($name, $results);

			$results[$name] = $constraints;
		}

		return $results;
	}


	/**
	 * Parse the nested relationships in a relation.
	 *
	 * @param  string  $name
	 * @param  array   $results
	 * @return array
	 */
	protected function parseNested($name, $results)
	{
		$progress = array();

		// If the relation has already been set on the result array, we will not set it
		// again, since that would override any constraints that were already placed
		// on the relationships. We will only set the ones that are not specified.
		foreach (explode('.', $name) as $segment)
		{
			$progress[] = $segment;

			if ( ! isset($results[$last = implode('.', $progress)]))
			{
 				$results[$last] = function() {};
 			}
		}

		return $results;
	}

	/**
	 * Build an array with lazy loading proxies for the current query
	 *
	 * @return array           
	 */
	protected function getLazyLoadingProxies($entity)
	{
		if(! $this->entityMap->relationsParsed() )
		{
			$initializer = new MapInitializer($this->mapper);
			$initializer->splitRelationsTypes($entity);
		}

		$singleRelations = $this->entityMap->getSingleRelationships();
		$manyRelations = $this->entityMap->getManyRelationships();

		$eagerLoads = array_keys($this->getEagerLoads());

		$allRelations = array_merge($manyRelations,$singleRelations);

		$lazyLoad = array_diff($allRelations, $eagerLoads);

		$proxies = [];

		if (count($lazyLoad) > 1)
		{
			//$entityProxy = new EntityProxy;
			//$collectionProxy = new CollectionProxy;
			
			foreach($lazyLoad as $relation)
			{
				if (in_array($relation, $singleRelations))
				{
					//$proxies[$relation] = $entityProxy;
					$proxies[$relation] = new EntityProxy;
				}
				if (in_array($relation, $manyRelations))
				{	
					//$proxies[$relation] = $collectionProxy;
					$proxies[$relation] = new CollectionProxy;
				}
			}
			
		}
		return $proxies;
	}

	/**
	 * Get the relationships being eagerly loaded.
	 *
	 * @return array
	 */
	public function getEagerLoads()
	{
		return $this->eagerLoad;
	}

	/**
	 * Set the relationships being eagerly loaded.
	 *
	 * @param  array  $eagerLoad
	 * @return void
	 */
	public function setEagerLoads(array $eagerLoad)
	{
		$this->eagerLoad = $eagerLoad;
	}

	/**
	 * Eager load the relationships for the entities.
	 *
	 * @param  array  $entities
	 * @return array
	 */
	public function eagerLoadRelations($entities)
	{
		foreach ($this->eagerLoad as $name => $constraints)
		{
			// For nested eager loads we'll skip loading them here and they will be set as an
			// eager load on the query to retrieve the relation so that they will be eager
			// loaded on that query, because that is where they get hydrated as models.
			if (strpos($name, '.') === false)
			{
				$entities = $this->loadRelation($entities, $name, $constraints);
			}
		}

		return $entities;
	}

	/**
	 * Eagerly load the relationship on a set of entities.
	 *
	 * @param  array     $entities
	 * @param  string    $name
	 * @param  \Closure  $constraints
	 * @return array
	 */
	protected function loadRelation(array $entities, $name, Closure $constraints)
	{
		// First we will "back up" the existing where conditions on the query so we can
		// add our eager constraints. Then we will merge the wheres that were on the
		// query back to it in order that any where conditions might be specified.
		$relation = $this->getRelation($name);

		$relation->addEagerConstraints($entities);

		call_user_func($constraints, $relation);

		$entities = $relation->initRelation($entities, $name);

		// Once we have the results, we just match those back up to their parent models
		// using the relationship instance. Then we just return the finished arrays
		// of models which have been eagerly hydrated and are readied for return.
		
		$results = $relation->getEager();

		return $relation->match($entities, $results, $name);
	}

	/**
	 * Get the relation instance for the given relation name.
	 *
	 * @param  string  $relation
	 * @return \Analogue\ORM\Relationships\Relationship
	 */
	public function getRelation($relation)
	{
		// We want to run a relationship query without any constrains so that we will
		// not have to remove these where clauses manually which gets really hacky
		// and is error prone while we remove the developer's own where clauses.
		$query = Relationship::noConstraints(function() use ($relation)
		{
			return $this->entityMap->$relation($this->getEntityInstance());
		});

		$nested = $this->nestedRelations($relation);

		// If there are nested relationships set on the query, we will put those onto
		// the query instances so that they can be handled after this relationship
		// is loaded. In this way they will all trickle down as they are loaded.
		if (count($nested) > 0)
		{
			$query->getQuery()->with($nested);
		}

		return $query;
	}

	/**
	 * Get the deeply nested relations for a given top-level relation.
	 *
	 * @param  string  $relation
	 * @return array
	 */
	protected function nestedRelations($relation)
	{
		$nested = array();

		// We are basically looking for any relationships that are nested deeper than
		// the given top-level relationship. We will just check for any relations
		// that start with the given top relations and adds them to our arrays.
		foreach ($this->eagerLoad as $name => $constraints)
		{
			if ($this->isNested($name, $relation))
			{
				$nested[substr($name, strlen($relation.'.'))] = $constraints;
			}
		}

		return $nested;
	}

	/**
	 * Determine if the relationship is nested.
	 *
	 * @param  string  $name
	 * @param  string  $relation
	 * @return bool
	 */
	protected function isNested($name, $relation)
	{
		$dots = str_contains($name, '.');

		return $dots && starts_with($name, $relation.'.');
	}

	/**
	 * Get the hydrated models without eager loading.
	 *
	 * @param  array  $columns
	 * @return Analogue\ORM\EntityCollection
	 */
	public function getEntities($columns = array('*'))
	{
		$results = $this->query->get($columns);

		$entities = array();

		$prototype = $this->getEntityInstance();

		$keyName = $this->entityMap->getKeyName();

		$tmpCache = [];

		$proxyLoaded = false;

		foreach($results as $result)
		{
			$instance = clone $prototype;
			
			$resultArray = (array) $result;

			$tmpCache[$resultArray[$keyName] ] = $resultArray;

			// Hydrate any embedded Value Object
			$this->hydrateValueObjects($resultArray);

			// We need to set the proxy for lazy loading on 
			// the first hydration pass. 
			if (! $proxyLoaded)
			{
				$instance->setEntityAttributes($resultArray);
				$proxies = $this->getLazyLoadingProxies($instance);
				$instance->setEntityAttributes($resultArray + $proxies);
				$proxyLoaded = true;
			}
			else
			{
				$instance->setEntityAttributes($resultArray + $proxies);
			}
			
			$entities[] = $instance;
		}

		$this->mapper->getEntityCache()->add($tmpCache);

		return $entities;
	}

	protected function hydrateValueObjects(& $attributes)
	{
		foreach($this->entityMap->getEmbeddables() as $localKey => $valueClass)
		{
			$this->hydrateValueObject($attributes, $localKey, $valueClass);
		}	
	}

	protected function hydrateValueObject(& $attributes, $localKey, $valueClass)
	{
		$map = Manager::getValueMap($valueClass);

		$embeddedAttributes = $map->getAttributes();

		//$nestedValueObjects = $map->getEmbeddables();

		$valueObject = Manager::getValueObjectInstance($valueClass);

		foreach($embeddedAttributes as $key)
		{
			$valueObject->$key = $attributes[$key];
			
			unset($attributes[$key]);
		}
		
		$attributes[$localKey] = $valueObject;
	}

	/**
	 * Get a new instance for the entity
	 * 
	 * @param  array  $attributes 
	 * @return Entity
	 */
	public function getEntityInstance(array $attributes = array())
	{
		return $this->mapper->newInstance($attributes);
	}

	/**
	 * Extend the builder with a given callback.
	 *
	 * @param  string  $name
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function macro($name, Closure $callback)
	{
		$this->macros[$name] = $callback;
	}

	/**
	 * Get the given macro by name.
	 *
	 * @param  string  $name
	 * @return \Closure
	 */
	public function getMacro($name)
	{
		return array_get($this->macros, $name);
	}

	/**
	 * Get a new query builder for the model's table.
	 *
	 * @return \Analogue\ORM\Query
	 */
	public function newQuery()
	{
		$builder = $this->newEloquentBuilder(
			$this->newBaseQueryBuilder(), $this->mapper
		);

		return $this->applyGlobalScopes($builder);
	}

	/**
	 * Get a new query builder instance for the connection.
	 *
	 * @return \Illuminate\Database\Query\Builder
	 */
	protected function newBaseQueryBuilder()
	{
		$conn = $this->getConnection();

		$grammar = $conn->getQueryGrammar();

		return new QueryBuilder($conn, $grammar, $conn->getPostProcessor());
	}

	/**
	 * Get the Mapper instance for this query
	 * 
	 * @return \Analogue\ORM\System\Mapper
	 */
	public function getMapper()
	{
		return $this->mapper;
	}

	/**
	 * Get the underlying query builder instance.
	 *
	 * @return \Illuminate\Database\Query\Builder|static
	 */
	public function getQuery()
	{
		return $this->query;
	}

	/**
	 * Dynamically handle calls into the query instance.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		if (isset($this->macros[$method]))
		{
			array_unshift($parameters, $this);

			return call_user_func_array($this->macros[$method], $parameters);
		}
		
		if(in_array($method, $this->blacklist))
		{
			// Method not exists is maybe more appropriate ?
			throw new \Exception("Analogue : Write Operation not permitted on  Analogue Query Builder");
		}

		$result = call_user_func_array(array($this->query, $method), $parameters);

		return in_array($method, $this->passthru) ? $result : $this;
	}

	
}