<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\Tests\DataCollector;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\DataCollector\SecurityDataCollector;
use Symfony\Bundle\SecurityBundle\Debug\TraceableFirewallListener;
use Symfony\Bundle\SecurityBundle\DependencyInjection\MainConfiguration;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\TraceableAccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\TraceableVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\FirewallMapInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SecurityDataCollectorTest extends TestCase
{
    public function testCollectWhenSecurityIsDisabled()
    {
        $collector = new SecurityDataCollector(null, null, null, null, null, null, true);
        $collector->collect(new Request(), new Response());

        $this->assertSame('security', $collector->getName());
        $this->assertFalse($collector->isEnabled());
        $this->assertFalse($collector->isAuthenticated());
        $this->assertFalse($collector->isImpersonated());
        $this->assertNull($collector->getImpersonatorUser());
        $this->assertNull($collector->getImpersonationExitPath());
        $this->assertNull($collector->getTokenClass());
        $this->assertFalse($collector->supportsRoleHierarchy());
        $this->assertCount(0, $collector->getRoles());
        $this->assertCount(0, $collector->getInheritedRoles());
        $this->assertEmpty($collector->getUser());
        $this->assertNull($collector->getFirewall());
    }

    public function testCollectWhenAuthenticationTokenIsNull()
    {
        $tokenStorage = new TokenStorage();
        $collector = new SecurityDataCollector($tokenStorage, $this->getRoleHierarchy(), null, null, null, null, true);
        $collector->collect(new Request(), new Response());

        $this->assertTrue($collector->isEnabled());
        $this->assertFalse($collector->isAuthenticated());
        $this->assertFalse($collector->isImpersonated());
        $this->assertNull($collector->getImpersonatorUser());
        $this->assertNull($collector->getImpersonationExitPath());
        $this->assertNull($collector->getTokenClass());
        $this->assertTrue($collector->supportsRoleHierarchy());
        $this->assertCount(0, $collector->getRoles());
        $this->assertCount(0, $collector->getInheritedRoles());
        $this->assertEmpty($collector->getUser());
        $this->assertNull($collector->getFirewall());
    }

    /** @dataProvider provideRoles */
    public function testCollectAuthenticationTokenAndRoles(array $roles, array $normalizedRoles, array $inheritedRoles)
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('hhamon', 'P4$$w0rD', $roles), 'provider', $roles));

        $collector = new SecurityDataCollector($tokenStorage, $this->getRoleHierarchy(), null, null, null, null, true);
        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        $this->assertTrue($collector->isEnabled());
        $this->assertTrue($collector->isAuthenticated());
        $this->assertFalse($collector->isImpersonated());
        $this->assertNull($collector->getImpersonatorUser());
        $this->assertNull($collector->getImpersonationExitPath());
        $this->assertSame('Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken', $collector->getTokenClass()->getValue());
        $this->assertTrue($collector->supportsRoleHierarchy());
        $this->assertSame($normalizedRoles, $collector->getRoles()->getValue(true));
        $this->assertSame($inheritedRoles, $collector->getInheritedRoles()->getValue(true));
        $this->assertSame('hhamon', $collector->getUser());
    }

    public function testCollectSwitchUserToken()
    {
        $adminToken = new UsernamePasswordToken(new InMemoryUser('yceruto', 'P4$$w0rD', ['ROLE_ADMIN']), 'provider', ['ROLE_ADMIN']);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new SwitchUserToken(new InMemoryUser('hhamon', 'P4$$w0rD', ['ROLE_USER', 'ROLE_PREVIOUS_ADMIN']), 'provider', ['ROLE_USER', 'ROLE_PREVIOUS_ADMIN'], $adminToken));

        $collector = new SecurityDataCollector($tokenStorage, $this->getRoleHierarchy(), null, null, null, null, true);
        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        $this->assertTrue($collector->isEnabled());
        $this->assertTrue($collector->isAuthenticated());
        $this->assertTrue($collector->isImpersonated());
        $this->assertSame('yceruto', $collector->getImpersonatorUser());
        $this->assertSame(SwitchUserToken::class, $collector->getTokenClass()->getValue());
        $this->assertTrue($collector->supportsRoleHierarchy());
        $this->assertSame(['ROLE_USER', 'ROLE_PREVIOUS_ADMIN'], $collector->getRoles()->getValue(true));
        $this->assertSame([], $collector->getInheritedRoles()->getValue(true));
        $this->assertSame('hhamon', $collector->getUser());
    }

    public function testGetFirewall()
    {
        $firewallConfig = new FirewallConfig('dummy', 'security.request_matcher.dummy', 'security.user_checker.dummy');
        $request = new Request();

        $firewallMap = $this
            ->getMockBuilder(FirewallMap::class)
            ->disableOriginalConstructor()
            ->getMock();
        $firewallMap
            ->expects($this->once())
            ->method('getFirewallConfig')
            ->with($request)
            ->willReturn($firewallConfig);

        $collector = new SecurityDataCollector(null, null, null, null, $firewallMap, new TraceableFirewallListener($firewallMap, new EventDispatcher(), new LogoutUrlGenerator()), true);
        $collector->collect($request, new Response());
        $collector->lateCollect();
        $collected = $collector->getFirewall();

        $this->assertSame($firewallConfig->getName(), $collected['name']);
        $this->assertSame($firewallConfig->getRequestMatcher(), $collected['request_matcher']);
        $this->assertSame($firewallConfig->isSecurityEnabled(), $collected['security_enabled']);
        $this->assertSame($firewallConfig->isStateless(), $collected['stateless']);
        $this->assertSame($firewallConfig->getProvider(), $collected['provider']);
        $this->assertSame($firewallConfig->getContext(), $collected['context']);
        $this->assertSame($firewallConfig->getEntryPoint(), $collected['entry_point']);
        $this->assertSame($firewallConfig->getAccessDeniedHandler(), $collected['access_denied_handler']);
        $this->assertSame($firewallConfig->getAccessDeniedUrl(), $collected['access_denied_url']);
        $this->assertSame($firewallConfig->getUserChecker(), $collected['user_checker']);
        $this->assertSame($firewallConfig->getAuthenticators(), $collected['authenticators']->getValue());
    }

    public function testGetFirewallReturnsNull()
    {
        $request = new Request();
        $response = new Response();

        // Don't inject any firewall map
        $collector = new SecurityDataCollector(null, null, null, null, null, null, true);
        $collector->collect($request, $response);
        $this->assertNull($collector->getFirewall());

        // Inject an instance that is not context aware
        $firewallMap = $this
            ->getMockBuilder(FirewallMapInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $collector = new SecurityDataCollector(null, null, null, null, $firewallMap, new TraceableFirewallListener($firewallMap, new EventDispatcher(), new LogoutUrlGenerator()), true);
        $collector->collect($request, $response);
        $this->assertNull($collector->getFirewall());

        // Null config
        $firewallMap = $this
            ->getMockBuilder(FirewallMap::class)
            ->disableOriginalConstructor()
            ->getMock();

        $collector = new SecurityDataCollector(null, null, null, null, $firewallMap, new TraceableFirewallListener($firewallMap, new EventDispatcher(), new LogoutUrlGenerator()), true);
        $collector->collect($request, $response);
        $this->assertNull($collector->getFirewall());
    }

    /**
     * @group time-sensitive
     */
    public function testGetListeners()
    {
        $request = new Request();
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $event->setResponse($response = new Response());
        $listener = function ($e) use ($event, &$listenerCalled) {
            $listenerCalled += $e === $event;
        };
        $firewallMap = $this
            ->getMockBuilder(FirewallMap::class)
            ->disableOriginalConstructor()
            ->getMock();
        $firewallMap
            ->expects($this->any())
            ->method('getFirewallConfig')
            ->with($request)
            ->willReturn(null);
        $firewallMap
            ->expects($this->once())
            ->method('getListeners')
            ->with($request)
            ->willReturn([[$listener], null, null]);

        $firewall = new TraceableFirewallListener($firewallMap, new EventDispatcher(), new LogoutUrlGenerator());
        $firewall->onKernelRequest($event);

        $collector = new SecurityDataCollector(null, null, null, null, $firewallMap, $firewall, true);
        $collector->collect($request, $response);

        $this->assertNotEmpty($collected = $collector->getListeners()[0]);
        $collector->lateCollect();
        $this->assertSame(1, $listenerCalled);
    }

    public static function providerCollectDecisionLog(): \Generator
    {
        $voter1 = new DummyVoter();
        $voter2 = new DummyVoter();

        $eventDispatcher = new class() implements EventDispatcherInterface {
            public function dispatch(object $event, string $eventName = null): object
            {
                return new \stdClass();
            }
        };
        $decoratedVoter1 = new TraceableVoter($voter1, $eventDispatcher);

        yield [
            MainConfiguration::STRATEGY_AFFIRMATIVE,
            [[
                'attributes' => ['view'],
                'object' => new \stdClass(),
                'result' => true,
                'voterDetails' => [
                    ['voter' => $voter1, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_ABSTAIN],
                    ['voter' => $voter2, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_ABSTAIN],
                ],
            ]],
            [$decoratedVoter1, $decoratedVoter1],
            [$voter1::class, $voter2::class],
            [[
                'attributes' => ['view'],
                'object' => new \stdClass(),
                'result' => true,
                'voter_details' => [
                    ['class' => $voter1::class, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_ABSTAIN],
                    ['class' => $voter2::class, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_ABSTAIN],
                ],
            ]],
        ];

        yield [
            MainConfiguration::STRATEGY_UNANIMOUS,
            [
                [
                    'attributes' => ['view', 'edit'],
                    'object' => new \stdClass(),
                    'result' => false,
                    'voterDetails' => [
                        ['voter' => $voter1, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_DENIED],
                        ['voter' => $voter1, 'attributes' => ['edit'], 'vote' => VoterInterface::ACCESS_DENIED],
                        ['voter' => $voter2, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_GRANTED],
                        ['voter' => $voter2, 'attributes' => ['edit'], 'vote' => VoterInterface::ACCESS_GRANTED],
                    ],
                ],
                [
                    'attributes' => ['update'],
                    'object' => new \stdClass(),
                    'result' => true,
                    'voterDetails' => [
                        ['voter' => $voter1, 'attributes' => ['update'], 'vote' => VoterInterface::ACCESS_GRANTED],
                        ['voter' => $voter2, 'attributes' => ['update'], 'vote' => VoterInterface::ACCESS_GRANTED],
                    ],
                ],
            ],
            [$decoratedVoter1, $decoratedVoter1],
            [$voter1::class, $voter2::class],
            [
                [
                    'attributes' => ['view', 'edit'],
                    'object' => new \stdClass(),
                    'result' => false,
                    'voter_details' => [
                        ['class' => $voter1::class, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_DENIED],
                        ['class' => $voter1::class, 'attributes' => ['edit'], 'vote' => VoterInterface::ACCESS_DENIED],
                        ['class' => $voter2::class, 'attributes' => ['view'], 'vote' => VoterInterface::ACCESS_GRANTED],
                        ['class' => $voter2::class, 'attributes' => ['edit'], 'vote' => VoterInterface::ACCESS_GRANTED],
                    ],
                ],
                [
                    'attributes' => ['update'],
                    'object' => new \stdClass(),
                    'result' => true,
                    'voter_details' => [
                        ['class' => $voter1::class, 'attributes' => ['update'], 'vote' => VoterInterface::ACCESS_GRANTED],
                        ['class' => $voter2::class, 'attributes' => ['update'], 'vote' => VoterInterface::ACCESS_GRANTED],
                    ],
                ],
            ],
        ];
    }

    /**
     * Test the returned data when AccessDecisionManager is a TraceableAccessDecisionManager.
     *
     * @param string $strategy             strategy returned by the AccessDecisionManager
     * @param array  $voters               voters returned by AccessDecisionManager
     * @param array  $decisionLog          log of the votes and final decisions from AccessDecisionManager
     * @param array  $expectedVoterClasses expected voter classes returned by the collector
     * @param array  $expectedDecisionLog  expected decision log returned by the collector
     *
     * @dataProvider providerCollectDecisionLog
     */
    public function testCollectDecisionLog(string $strategy, array $decisionLog, array $voters, array $expectedVoterClasses, array $expectedDecisionLog)
    {
        $accessDecisionManager = $this
            ->getMockBuilder(TraceableAccessDecisionManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStrategy', 'getVoters', 'getDecisionLog'])
            ->getMock();

        $accessDecisionManager
            ->expects($this->any())
            ->method('getStrategy')
            ->willReturn($strategy);

        $accessDecisionManager
            ->expects($this->any())
            ->method('getVoters')
            ->willReturn($voters);

        $accessDecisionManager
            ->expects($this->any())
            ->method('getDecisionLog')
            ->willReturn($decisionLog);

        $dataCollector = new SecurityDataCollector(null, null, null, $accessDecisionManager, null, null, true);
        $dataCollector->collect(new Request(), new Response());

        $this->assertEquals($dataCollector->getAccessDecisionLog(), $expectedDecisionLog, 'Wrong value returned by getAccessDecisionLog');

        $this->assertSame(
            array_map(function ($classStub) { return (string) $classStub; }, $dataCollector->getVoters()),
            $expectedVoterClasses,
            'Wrong value returned by getVoters'
        );
        $this->assertSame($dataCollector->getVoterStrategy(), $strategy, 'Wrong value returned by getVoterStrategy');
    }

    public static function provideRoles()
    {
        return [
            // Basic roles
            [
                ['ROLE_USER'],
                ['ROLE_USER'],
                [],
            ],
            // Inherited roles
            [
                ['ROLE_ADMIN'],
                ['ROLE_ADMIN'],
                ['ROLE_USER', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
            [
                ['ROLE_ADMIN', 'ROLE_OPERATOR'],
                ['ROLE_ADMIN', 'ROLE_OPERATOR'],
                ['ROLE_USER', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
        ];
    }

    private function getRoleHierarchy()
    {
        return new RoleHierarchy([
            'ROLE_ADMIN' => ['ROLE_USER', 'ROLE_ALLOWED_TO_SWITCH'],
            'ROLE_OPERATOR' => ['ROLE_USER'],
        ]);
    }
}

class DummyVoter implements VoterInterface
{
    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
    }
}
