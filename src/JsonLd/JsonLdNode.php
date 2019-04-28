<?php

namespace ActivityPub\JsonLd;

use ActivityPub\JsonLd\Dereferencer\DereferencerInterface;
use ActivityPub\JsonLd\Exceptions\PropertyNotDefinedException;
use ArrayAccess;
use BadMethodCallException;
use InvalidArgumentException;
use ML\JsonLD\JsonLD;
use stdClass;

class JsonLdNode implements ArrayAccess
{
    /**
     * This node's id. May be null or a temporary id if this is a blank node.
     * @var string|null
     */
    private $id;

    /**
     * The JSON-LD expanded representation of the node.
     * @var stdClass
     */
    private $expanded;

    /**
     * The JSON-LD context.
     * @var array|stdClass|string
     */
    private $context;

    /**
     * The factory used to construct this node.
     * @var JsonLdNodeFactory
     */
    private $factory;

    /**
     * @var DereferencerInterface
     */
    private $dereferencer;

    /**
     * This node's view of the JSON-LD graph.
     * @var JsonLdGraph
     */
    private $graph;

    // TODO support backreferences

    /**
     * JsonLdNode constructor.
     * @param stdClass $jsonLd The JSON-LD input.
     * @param string|array|stdClass $context The JSON-LD context.
     * @param JsonLdNodeFactory $factory The factory used to construct this instance.
     * @param DereferencerInterface $dereferencer
     * @param JsonLdGraph $graph The JSON-LD graph this node is a part of.
     */
    public function __construct( $jsonLd, $context, JsonLdNodeFactory $factory, DereferencerInterface $dereferencer, JsonLdGraph $graph )
    {
        $this->factory = $factory;
        $this->dereferencer = $dereferencer;
        if ( $jsonLd == new stdClass() ) {
            $this->expanded = new stdClass();
        } else {
            $this->expanded = JsonLD::expand( $jsonLd )[0];
        }
        if ( property_exists( $this->expanded, '@id' ) ) {
            $idProp = '@id';
            $this->id = $this->expanded->$idProp;
        }
        $this->context = $context;
        $this->graph = $graph;
        $this->graph->addNode( $this );
    }

    /**
     * Gets this node's id, if it has one. Could be null or a temporary id if this is a blank node.
     * @return string|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Sets this node's ID to $id.
     * @param string $id
     * @throws BadMethodCallException If this node already has an ID set.
     */
    public function setId( $id )
    {
        if ( ! is_null( $this->getId() ) ) {
            throw new BadMethodCallException( 'Node already has an ID' );
        }
        $this->id = $id;
    }

    /**
     * Cardinality-one get. Gets the single value for the property named $name.
     * If there are multiple values defined for the property, only the first value is returned.
     * @param string $name The property name to get.
     * @return mixed  A single property value.
     * @throws PropertyNotDefinedException If no property named $name exists.
     */
    public function get( $name )
    {
        $expandedName = $this->expandName( $name );
        if ( property_exists( $this->expanded, $expandedName ) ) {
            return $this->resolveProperty( $this->expanded->$expandedName[0] );
        }
        throw new PropertyNotDefinedException( $name );
    }

    /**
     * Cardinality-many get. Gets all the values for the property named $name.
     * If there is only one value defined for the property, it is returned as a length-1 array.
     * @param string $name The property name to get.
     * @return mixed  A single property value.
     * @throws PropertyNotDefinedException If no property named $name exists.
     */
    public function getMany( $name )
    {
        $expandedName = $this->expandName( $name );
        if ( property_exists( $this->expanded, $expandedName ) ) {
            return $this->resolveProperty( $this->expanded->$expandedName );
        }
        throw new PropertyNotDefinedException( $name );
    }

    /**
     * A convenience wrapper around $this->get( $name ). Cardinality-one.
     * @param string $name
     * @return mixed
     * @throws PropertyNotDefinedException
     */
    public function __get( $name )
    {
        return $this->get( $name );
    }

    private function resolveProperty( &$property )
    {
        if ( is_array( $property ) ) {
            return array_map( array( $this, 'resolveProperty'), $property );
        } else if ( $property instanceof stdClass && property_exists( $property, '@id') ) {
            // Only dereference if @id is the only property present
            if ( count( get_object_vars( $property ) ) > 1 ) {
                return $property;
            }
            $idProp = '@id';
            $iri = $property->$idProp;
            $dereferenced = $this->dereferencer->dereference( $iri );
            $expanded = JsonLD::expand( $dereferenced )[0];
            $property = $expanded;
            $referencedNode = $this->graph->getNode( $property->$idProp );
            if ( is_null( $referencedNode) ) {
                $referencedNode = $this->factory->newNode( $property, $this->graph );
            }
            return $referencedNode;
        } else if ( $property instanceof stdClass && property_exists( $property, '@value' ) ) {
            $value = '@value';
            return $property->$value;
        } else if ( $property instanceof stdClass ) {
            $referencedNode = $this->factory->newNode( $property, $this->graph );
            return $referencedNode;
        } else {
            return $property;
        }
    }

    /**
     * Sets the value for a new or existing property on the node.
     * If the property already exists, the new value overwrites the old value(s).
     * @param string $name
     * @param string|stdClass|array $value
     */
    public function set( $name, $value )
    {
        $expandedName = $this->expandName( $name );
        if ( $expandedName === '@id' && ! $this->isBlankNode() ) {
            throw new InvalidArgumentException( 'This node already has an id.' );
        }
        $expandedValue = $this->expandValue( $expandedName, $value );
        $this->expanded->$expandedName = $expandedValue;
        if ( $expandedName === '@id' ) {
            $this->graph->nameBlankNode( $this->getId(), $expandedValue );
            $this->id = $expandedValue;
        }
    }

    public function add( $name, $value )
    {
        $expandedName = $this->expandName( $name );
        if ( $expandedName === '@id' ) {
            throw new InvalidArgumentException( 'Cannot add to the @id property.' );
        }
        $expandedValue = $this->expandValue( $expandedName, $value );
        if ( property_exists( $this->expanded, $expandedName ) ) {
            $this->expanded->$expandedName = array_merge( $this->expanded->$expandedName, $expandedValue );
        } else {
            $this->expanded->$expandedName = $expandedValue;
        }
    }

    /**
     * Convenience wrapper around $this->set().
     * If the property already exists, the new value overwrites the old value(s).
     * @param string $name
     * @param string|stdClass|array $value
     */
    public function __set( $name, $value )
    {
        return $this->set( $name, $value );
    }

    public function has( $name )
    {
        $expandedName = $this->expandName( $name );
        return property_exists( $this->expanded, $expandedName );
    }

    /**
     * Given an already-expanded name and the current context, expands value so that it can be stored in $expanded.
     * @param string $expandedName
     * @param string|stdClass|array $value
     * @return array|stdClass
     */
    private function expandValue( $expandedName, $value )
    {
        $nameToValue = (object) array( '@context' => $this->context, $expandedName => $value );
        $expanded = JsonLD::expand( $nameToValue )[0];
        $expandedValue = $expanded->$expandedName;
        return $expandedValue;
    }

    /**
     * Clears the property named $name.
     * @param string $name
     */
    public function clear( $name )
    {
        $expandedName = $this->expandName( $name );
        unset( $this->expanded->expandedName );
    }

    /**
     * Returns the node as an object.
     * @return stdClass
     */
    public function asObject()
    {
        return JsonLD::compact( $this->expanded, $this->context );
    }

    /**
     * Returns true if this node is a blank node (even if it has a temporary id).
     * @return bool
     */
    public function isBlankNode()
    {
        return property_exists( $this->expanded, '@id' );
    }

    /**
     * Whether a offset exists
     * @link https://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists( $offset )
    {
        return property_exists( $this->expanded, (string) $offset );
    }

    /**
     * A convenience wrapper around $this->get(). Cardinality-one.
     * Offset to retrieve
     * @link https://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @since 5.0.0
     * @throws PropertyNotDefinedException
     */
    public function offsetGet( $offset )
    {
        return $this->get( (string) $offset );
    }

    /**
     * Offset to set
     * @link https://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetSet( $offset, $value )
    {
        $this->set( (string) $offset, $value );
    }

    /**
     * Offset to unset
     * @link https://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset( $offset )
    {
        $this->clear( (string) $offset );
    }

    /**
     * Resolves $name to a full IRI given the JSON-LD context of this node.
     * @param string $name The name of the property to resolve.
     * @return string The expanded name.
     */
    private function expandName( $name )
    {
        // TODO memoize this function
        $dummyObj = (object) array(
            '@context' => $this->context,
            $name => '_dummyValue',
        );
        $expanded = (array) JsonLD::expand( $dummyObj )[0];
        return array_keys( $expanded )[0];
    }
}