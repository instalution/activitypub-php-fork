<?php
namespace ActivityPub\Test\Objects;

use ActivityPub\Auth\AuthService;
use ActivityPub\Objects\ContextProvider;
use ActivityPub\Objects\CollectionsService;
use ActivityPub\Test\TestUtils\TestUtils;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class CollectionsServiceTest extends TestCase
{
    private $collectionsService;

    public function setUp()
    {
        $authService = new AuthService();
        $contextProvider = new ContextProvider();
        $this->collectionsService = new CollectionsService(
            4, $authService, $contextProvider
        );
    }

    public function testCollectionsService()
    {
        $testCases = array(
            array(
                'id' => 'lessThanOnePage',
                'collection' => array(
                    '@context' => array(
                        'https://www.w3.org/ns/activitystreams',
                        'https://w3id.org/security/v1',
                    ),
                    'id' => 'https://example.com/objects/1',
                    'type' => 'OrderedCollection',
                    'orderedItems' => array(
                        array(
                            'id' => 'https://example.com/objects/2',
                        ),
                        array(
                            'id' => 'https://example.com/objects/3',
                        ),
                        array(
                            'id' => 'https://example.com/objects/4',
                        ),
                    )
                ),
                'request' => Request::create(
                    'https://example.com/objects/1',
                    Request::METHOD_GET
                ),
                'expectedResult' => array(
                    '@context' => array(
                        'https://www.w3.org/ns/activitystreams',
                        'https://w3id.org/security/v1',
                    ),
                    'id' => 'https://example.com/objects/1',
                    'type' => 'OrderedCollection',
                    'first' => array(
                        '@context' => array(
                            'https://www.w3.org/ns/activitystreams',
                            'https://w3id.org/security/v1',
                        ),
                        'id' => 'https://example.com/objects/1?offset=0',
                        'type' => 'OrderedCollectionPage',
                        'partOf' => 'https://example.com/objects/1',
                        'startIndex' => 0,
                        'orderedItems' => array(
                            array(
                                'id' => 'https://example.com/objects/2',
                            ),
                            array(
                                'id' => 'https://example.com/objects/3',
                            ),
                            array(
                                'id' => 'https://example.com/objects/4',
                            ),
                        ),
                    ),
                ),
            ),
        );
        foreach ( $testCases as $testCase ) {
            $actual = $this->collectionsService->pageAndFilterCollection(
                $testCase['request'], TestUtils::objectFromArray( $testCase['collection'] )
            );
            $this->assertEquals(
                $testCase['expectedResult'], $actual, "Error on test $testCase[id]"
            );
        }
    }
}
?>
