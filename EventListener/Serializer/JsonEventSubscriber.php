<?php

/*
 * This file is part of the Mango package.
 *
 * (c) Steffen Brem <steffenbrem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mango\Bundle\JsonApiBundle\EventListener\Serializer;

use Doctrine\Common\Collections\Collection;
use JMS\Serializer\Context;
use JMS\Serializer\EventDispatcher\Events;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use JMS\Serializer\VisitorInterface;
use Mango\Bundle\JsonApiBundle\Configuration\Metadata\ClassMetadata;
use Mango\Bundle\JsonApiBundle\Configuration\Relationship;
use Mango\Bundle\JsonApiBundle\Serializer\Exclusion\RelationshipExclusionStrategy;
use Mango\Bundle\JsonApiBundle\Serializer\JsonApiSerializationVisitor;
use Metadata\MetadataFactoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * @author Steffen Brem <steffenbrem@gmail.com>
 */
class JsonEventSubscriber implements EventSubscriberInterface
{
    /**
     * Keep track of all included relationships, so that we do not duplicate them
     *
     * @var array
     */
    protected $includedRelationships = array();

    /**
     * @var MetadataFactoryInterface
     */
    protected $hateoasMetadataFactory;

    /**
     * @var MetadataFactoryInterface
     */
    protected $jmsMetadataFactory;

    /**
     * @var PropertyNamingStrategyInterface
     */
    protected $namingStrategy;

    /**
     * @param MetadataFactoryInterface        $hateoasMetadataFactory
     * @param PropertyNamingStrategyInterface $namingStrategy
     */
    public function __construct(MetadataFactoryInterface $hateoasMetadataFactory, MetadataFactoryInterface $jmsMetadataFactory, PropertyNamingStrategyInterface $namingStrategy)
    {
        $this->hateoasMetadataFactory = $hateoasMetadataFactory;
        $this->jmsMetadataFactory = $jmsMetadataFactory;
        $this->namingStrategy = $namingStrategy;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            array(
                'event' => Events::POST_SERIALIZE,
                'format' => 'json',
                'method' => 'onPostSerialize',
            ),
        );
    }

    public function onPostSerialize(ObjectEvent $event)
    {
        $visitor = $event->getVisitor();
        $object = $event->getObject();
        $context = $event->getContext();

        /** @var ClassMetadata $metadata */
        $metadata = $this->hateoasMetadataFactory->getMetadataForClass(get_class($object));

        /** @var \JMS\Serializer\Metadata\ClassMetadata $jmsMetadata */
        $jmsMetadata = $this->jmsMetadataFactory->getMetadataForClass(get_class($object));

        $propertyAccessor = PropertyAccess::createPropertyAccessor();


        if ($visitor instanceof JsonApiSerializationVisitor) {
            $this->prependData($visitor, array(
                'type' => $metadata->getResource()->getType()
            ));

            $relationships = array();

            foreach ($metadata->getRelationships() as $relationship) {
                $relationshipPropertyName = $relationship->getName();

                $relationshipObject = $propertyAccessor->getValue($object, $relationshipPropertyName);

                // if there is no data for this relationship, then we can skip it
                if ($this->isEmpty($relationshipObject)) {
                    continue;
                }

                // JMS Serializer support
                if (!isset($jmsMetadata->propertyMetadata[$relationshipPropertyName])) {
                    continue;
                }
                $jmsPropertyMetadata = $jmsMetadata->propertyMetadata[$relationshipPropertyName];
                $relationshipPayloadKey = $this->namingStrategy->translateName($jmsPropertyMetadata);

                $relationshipData =& $relationships[$relationshipPayloadKey]['data'];
                $relationshipData = array();

                // hasMany relationship
                if ($this->isIteratable($relationshipObject)) {
                    foreach ($relationshipObject as $item) {
                        $relationshipData[] = $this->processRelationship($item, $relationship, $context);
                    }
                } // belongsTo relationship
                else {
                    $relationshipData = $this->processRelationship($relationshipObject, $relationship, $context);
                }
            }

            if ($relationships) {
                $visitor->addData('relationships', $relationships);
            }

            $root = (array)$visitor->getRoot();
            $root['included'] = array_values($this->includedRelationships);
            $visitor->setRoot($root);
        }
    }

    /**
     * @param              $object
     * @param Relationship $relationship
     * @param Context      $context
     * @return array
     */
    protected function processRelationship($object, Relationship $relationship, Context $context)
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        $relationshipId = $propertyAccessor->getValue($object, 'id');

        /** @var ClassMetadata $relationshipMetadata */
        $relationshipMetadata = $this->hateoasMetadataFactory->getMetadataForClass(get_class($object));

        // contains the relations type and id
        $relationshipDataArray = $this->getRelationshipDataArray($relationshipMetadata, $relationshipId);

        // only include this relationship if it is needed
        if ($relationship->isIncludedByDefault() && $this->canIncludeRelationship($relationshipMetadata, $relationshipId)) {
            $includedRelationship = $relationshipDataArray; // copy data array so we do not override it with our reference
            $this->includedRelationships[] =& $includedRelationship;
            $includedRelationship = $context->accept($object); // override previous reference with the serialized data
        }

        // the relationship data can only contain one reference to another resource
        return $relationshipDataArray;
    }

    /**
     * @param ClassMetadata $classMetadata
     * @param               $id
     * @return array
     */
    protected function getRelationshipDataArray(ClassMetadata $classMetadata, $id)
    {
        return array(
            'type' => $classMetadata->getResource()->getType(),
            'id' => $id
        );
    }

    /**
     * Checks if an object is really empty, also if it is iteratable and has zero items.
     *
     * @param $object
     * @return bool
     */
    protected function isEmpty($object)
    {
        return empty($object) || ($this->isIteratable($object) && count($object) === 0);
    }

    /**
     * @param $data
     * @return bool
     */
    protected function isIteratable($data)
    {
        return (is_array($data) || $data instanceof \Traversable);
    }


    /**
     * @param ClassMetadata $classMetadata
     * @param               $id
     * @return bool
     */
    protected function canIncludeRelationship(ClassMetadata $classMetadata, $id)
    {
        foreach ($this->includedRelationships as $includedRelationship) {
            if ($includedRelationship['type'] === $classMetadata->getResource()->getType()
                && $includedRelationship['id'] === $id
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Prepend some data
     *
     * @param VisitorInterface $visitor
     * @param array            $prependData
     */
    protected function prependData(VisitorInterface $visitor, array $prependData)
    {
        $refl = new \ReflectionObject($visitor);
        $property = $refl->getParentClass()->getParentClass()->getProperty('data');
        $property->setAccessible(true);

        $data = $property->getValue($visitor);
        $data = $prependData + $data;

        $property->setValue($visitor, $data);
    }
}