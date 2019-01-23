<?php
namespace ActivityPub\Test\Activities;

use ActivityPub\Activities\OutboxActivityEvent;
use ActivityPub\Activities\NonActivityHandler;
use ActivityPub\Objects\ContextProvider;
use ActivityPub\Objects\IdProvider;
use ActivityPub\Test\TestUtils\TestActivityPubObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class NonActivityHandlerTest extends TestCase
{
    public function testNonActivityHandler()
    {
        $contextProvider = new ContextProvider();
        $idProvider = $this->createMock( IdProvider::class );
        $idProvider->method( 'getId' )->willReturn( 'id1' );
        $nonActivityHandler = new NonActivityHandler( $contextProvider, $idProvider );
        $testCases = array(
            array(
                'id' => 'testItWrapsNonObjectActivity',
                'activity' => array(
                    'type' => 'Note'
                ),
                'actor' => TestActivityPubObject::fromArray( array(
                    'id' => 'https://example.com/actor/1',
                ) ),
                'expectedActivity' => array(
                    '@context' => ContextProvider::DEFAULT_CONTEXT,
                    'type' => 'Create',
                    'id' => 'id1',
                    'actor' => 'https://example.com/actor/1',
                    'object' => array(
                        'type' => 'Note',
                    ),
                ),
            ),
            array(
                'id' => 'testItDoesNotWrapActivity',
                'activity' => array(
                    'type' => 'Update'
                ),
                'actor' => TestActivityPubObject::fromArray( array(
                    'id' => 'https://example.com/actor/1',
                ) ),
                'expectedActivity' => array(
                    'type' => 'Update',
                ),
            ),
            array(
                'id' => 'testItPassesAudience',
                'activity' => array(
                    'type' => 'Note',
                    'audience' => array(
                        'foo',
                    ),
                    'to' => array(
                        'bar',
                    ),
                    'bcc' => array(
                        'baz',
                    ),
                ),
                'actor' => TestActivityPubObject::fromArray( array(
                    'id' => 'https://example.com/actor/1',
                ) ),
                'expectedActivity' => array(
                    '@context' => ContextProvider::DEFAULT_CONTEXT,
                    'type' => 'Create',
                    'id' => 'id1',
                    'actor' => 'https://example.com/actor/1',
                    'object' => array(
                        'type' => 'Note',
                        'audience' => array(
                            'foo',
                        ),
                        'to' => array(
                            'bar',
                        ),
                        'bcc' => array(
                            'baz',
                        ),
                    ),
                    'audience' => array(
                        'foo',
                    ),
                    'to' => array(
                        'bar',
                    ),
                    'bcc' => array(
                        'baz',
                    ),
                ),
            )
        );
        foreach ( $testCases as $testCase ) {
            $actor = $testCase['actor'];
            $activity = $testCase['activity'];
            $request = Request::create( 'https://example.com/whatever' );
            $event = new OutboxActivityEvent( $activity, $actor, $request );
            $nonActivityHandler->handle( $event );
            $this->assertEquals(
                $testCase['expectedActivity'],
                $event->getActivity(),
                "Error on test $testCase[id]"
            );
        }
    }
}
?>
