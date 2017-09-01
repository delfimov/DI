<?php

namespace DElfimov\DI;

use \Psr\Container\ContainerInterface;
use \DElfimov\DI\ContainerException;
use \DElfimov\DI\NotFoundException;

class Container implements ContainerInterface
{
    /**
     * Rules which have been set using addRule()
     * @var array
     */
    protected $rules = [];

    /**
     * A cache of closures based on class name so each class is only reflected once
     * @var array
     */
    protected $cache = [];

    /**
     * Stores any instances marked as 'shared' so create() can return the same instance
     * @var array
     */
    protected $instances = [];

    /**
     * Cache key
     */
    const CACHE_KEY = 'di.cache';

    /**
     * Container constructor.
     * @param array $rules
     * @param array $cachedRules
     */
    public function __construct(array $rules = [], $cachedRules = null)
    {
        if (isset($cachedRules)) {
            $this->rules = $cachedRules;
        } else {
            $this->addRules($rules);
        }
    }

    /**
     * @param array $rules
     */
    public function addRules(array $rules = [])
    {
        foreach ($rules as $name => $rule) {
            $this->addRule($name, $rule);
        }
    }

    /**
     * @return array rules container
     */
    public function getRules()
    {
        return $this->rules;
    }


    /**
     * Add a rule $rule to the class $name see https://r.je/dice.html#example3 for $rule format
     *
     * @param string $name
     * @param array  $rule
     */
    public function addRule($name, array $rule)
    {
        // If we have a rule with the same name,
        // or a rule applied for a parent class with 'inherit' set to true
        // or a default rule ('*') is specified,
        // then merge new rule with existing
        $this->rules[$name] = array_merge($this->getRule($name), $rule);
    }

    /**
     * Is there a rule for a specified $name
     *
     * @param string $name rule name
     *
     * @return bool
     */
    public function hasRule($name)
    {
        return isset($this->rules[$name]);
    }


    /**
     * Returns the rule that will be applied to the class $name in create()
     *
     * @param string $name rule name
     * @return array rule
     */
    public function getRule($name)
    {
        if ($this->hasRule($name)) {
            return $this->rules[$name];
        }
        foreach ($this->rules as $key => $rule) {
            // Find a rule which matches the class described in $name where:
            if ($key !== '*' && !empty($rule['inherit'])) { // current rule is not a default and inheritance set to true
                if (empty($rule['instanceOf'])) {
                    $parentClass = $key;                    // It's a classname
                } else {
                    $parentClass = $rule['instanceOf'];     // or it's an instance of some class
                }
                if (is_subclass_of($name, $parentClass)) {  // The rule is applied to a parent class
                    return $rule;
                }
            }
        }
        // Matching rules not found, return the default rule, or an empty array
        return isset($this->rules['*']) ? $this->rules['*'] : [];
    }

    /**
     * Returns a fully constructed object based on $name
     * using $args and $share as constructor arguments if supplied.
     * Will cache closures for later use
     *
     * @param string $name class name
     * @param array $args arguments
     * @param array $share
     *
     * @return mixed
     */
    public function get($name, array $args = [], array $share = [])
    {
        // If it's a shared instance - return it. It's faster than calling a closure.
        if (!empty($this->instances[$name])) {
            return $this->instances[$name];
        }

        // If closure is not cached, then create a closure for creating the object
        // this should only ever be done once per class and get cached
        if (empty($this->cache[$name])) {
            $this->cache[$name] = $this->getClosure($name, $this->getRule($name));
        }

        // Call the closure which will return initialized class instance
        return $this->cache[$name]($args, $share);
    }

    /**
     * Is specified $name could be resolved using DI Container.
     *
     * @param string $name
     *
     * @return bool
     */
    public function has($name)
    {
        $rule = $this->getRule($name);
        if (!empty($rule['instanceOf'])) {
            $name = $rule['instanceOf'];
        }
        return class_exists($name);
    }

    /**
     * Returns a closure for creating object $name based on $rule,
     * caching the reflection object for later use
     *
     * @param string $name class name
     * @param array $rule rule
     *
     * @return mixed
     * @throws NotFoundException
     */
    private function getClosure($name, array $rule)
    {
        if (isset($rule['instanceOf'])) {
            $className = $rule['instanceOf'];
        } else {
            $className = $name;
        }

        if (!empty($rule['static'])) {
            return function (array $args, array $share) use ($className, $rule) {
                try {
                    return $className::$rule['static'](...$args);
                } catch (\ReflectionException $Exception) {
                    throw new NotFoundException('Class "' . $className . '" not found."');
                }
            };
/*            $rfl = new \ReflectionMethod($this->instances[$name], $rule['static']);
            $this->instances[$name] = $rfl->invokeArgs($this->instances[$name], $params($args, $share));*/
        };

        // Try to reflect requested class
        try {
            $class = new \ReflectionClass($className);
        } catch (\ReflectionException $Exception) {
            throw new NotFoundException('Class "' . $className . '" not found."');
        }
        // get constructor
        $constructor = $class->getConstructor();

        if (empty($constructor)) {
            $params = null;
        } else {
            // Create parameter generating function in order to cache reflection on the parameters.
            // This way $reflect->getParameters() only ever gets called once
            $params = $this->getParams($constructor, $rule);
        }

        // Get a closure based on the type of object being created: normal or constructorless
        if (!empty($rule['shared'])) {
            $closure = function (array $args, array $share) use ($class, $name, $constructor, $params) {
                // Shared instance: create the class without calling the constructor
                // and write to instances cache as '\name' and 'name'
                $instanceName = ltrim($name, '\\');
                $this->instances[$instanceName] = $class->newInstanceWithoutConstructor();
                $this->instances['\\' . $instanceName] = $this->instances[$instanceName];
                if (!empty($constructor)) {
                    // Now call this constructor after constructing all the dependencies.
                    // This avoids problems with cyclic references (issue #7)
                    $constructor->invokeArgs($this->instances[$name], $params($args, $share));
                }
                return $this->instances[$name];
            };
        } elseif ($params) {
            $closure = function (array $args, array $share) use ($class, $params) {
                //This class has depenencies, call the $params closure to generate them based on $args and $share
                return new $class->name(...$params($args, $share));
            };
        } else {
            $closure = function () use ($class) {
                //No constructor arguments, just instantiate the class
                return new $class->name;
            };
        }
        // If there are shared instances, create them and merge them with shared instances higher up the object graph
        if (isset($rule['shareInstances'])) {
            $closure = function (array $args, array $share) use ($closure, $rule) {
                return $closure($args, array_merge($args, $share, array_map([$this, 'get'], $rule['shareInstances'])));
            };
        }
        // When $rule['call'] is set, wrap the closure in another closure
        // which will call the required methods after constructing the object
        // By putting this in a closure, the loop is never executed unless call is actually set
        if (isset($rule['call'])) {
            return function (array $args, array $share) use ($closure, $class, $rule) {
                //Construct the object using the original closure
                $object = $closure($args, $share);
                foreach ($rule['call'] as $call) {
                    //Generate the method arguments using getParams() and call the returned closure
                    // (in php7 will be ()() rather than __invoke)
                    $params = $this->getParams(
                        $class->getMethod($call[0]),
                        [
                            'shareInstances' => isset($rule['shareInstances']) ? $rule['shareInstances'] : []
                        ]
                    )->__invoke($this->expand(isset($call[1]) ? $call[1] : []));
                    $object->{$call[0]}(...$params);
                }
                return $object;
            };
        } else {
            return $closure;
        }
    }

    /**
     * looks for 'instance' array keys in $param
     * and when found returns an object based
     * on the value see https://r.je/dice.html#example3-1
     *
     * @param mixed $param params
     * @param array $share shared parameters
     * @param bool  $createFromString array with 'instanceOf' found, should try to initialize matching class
     *
     * @return mixed
     */
    private function expand($param, array $share = [], $createFromString = false)
    {
        if (is_array($param) && isset($param['instanceOf'])) {
            //Call or return the value sored under the key 'instance'
            //For ['instance' => ['className', 'methodName'] construct the instance before calling it
            $args = [];
            if (isset($param['construct'])) {
                $args = $this->expand($param['construct']);
            }
            if (is_array($param['instanceOf'])) {
                $param['instanceOf'][0] = $this->expand($param['instanceOf'][0], $share, true);
            }
            if (is_callable($param['instanceOf'])) {
                return call_user_func(
                    $param['instanceOf'],
                    ...$args
                );
            } else {
                return $this->get($param['instanceOf'], array_merge($args, $share));
            }
        } elseif (is_array($param)) {
            // Recursively search for 'instanceOf' keys in $param
            foreach ($param as &$value) {
                $value = $this->expand($value, $share);
            }
        }

        // 'instance' wasn't found, return the value unchanged
        return is_string($param) && $createFromString ? $this->get($param) : $param;
    }

    /**
     * Returns a closure that generates arguments
     * for $method based on $rule and any $args
     * passed into the closure
     *
     * @param \ReflectionMethod $method
     * @param array $rule rule
     *
     * @return callable
     */
    private function getParams(\ReflectionMethod $method, array $rule)
    {
        // Cache parameters in $paramInfo to minimize reflections usage
        $paramInfo = [];
        /**
         * @var $param \ReflectionParameter
         */
        foreach ($method->getParameters() as $param) {
            $substitution = false;
            $className = false;
            $class = $param->getClass();
            if (isset($class)) {
                $className = $class->name;
                if (isset($rule['substitutions']) && isset($rule['substitutions'][$className])) {
                    $substitution = $rule['substitutions'][$className];
                }
            }
            $paramInfo[] = [$className, $param, $substitution];
        }

        // Return a closure that uses cached information to generate the arguments for the method
        return function (array $args, array $share = []) use ($paramInfo, $rule) {
            // Now merge all the possible parameters: user-defined in the rule via construct,
            // shared instances and the $args argument from $di->get();
            if (isset($rule['construct']) || !empty($share)) {
                $args = array_merge(
                    $args,
                    isset($rule['construct']) ? $this->expand($rule['construct'], $share) : [],
                    $share
                );
            }

            $parameters = [];

            // Now find a value for each method parameter
            foreach ($paramInfo as list($class, $param, $sub)) {
                /**
                 * @var $param \ReflectionParameter
                 */
                // First loop through $args and see whether or not
                // each value can match the current parameter based on type hint
                if (!empty($args)) { // This if statement actually gives a ~10% speed increase when $args isn't set
                    foreach ($args as $i => $arg) {
                        if ($class !== false
                            && ($arg instanceof $class || ($arg === null && $param->allowsNull()))
                        ) {
                            // The argument matched, store it and remove it from $args
                            // so it won't wrongly match another parameter
                            $parameters[] = array_splice($args, $i, 1)[0];
                            // Move on to the next parameter
                            continue 2;
                        }
                    }
                }

                if ($class !== false) {
                    // When nothing from $args matches but a class is type hinted,
                    // create an instance to use, using a substitution if set
                    if ($sub === false) {
                        $parameters[] = $this->get($class, [], $share);
                    } else {
                        $parameters[] = $this->expand($sub, $share, true);
                    }
                } elseif (!empty($args)) {
                    // There is no type hint, take the next available value from $args
                    // (and remove it from $args to stop it being reused)
                    $parameters[] = $this->expand(array_shift($args));
                } elseif ($param->isVariadic()) {
                    // For variadic parameters, provide remaining $args
                    $parameters = array_merge($parameters, $args);
                } elseif ($param->isDefaultValueAvailable()) {
                    // There's no type hint and nothing left in $args, provide the default value...
                    $parameters[] = $param->getDefaultValue();
                } else {
                    // ...or null
                    $parameters[] = null;
                }
            }
            // variadic functions will only have one argument.
            // To account for those, append any remaining arguments to the list
            return $parameters;
        };
    }
}
