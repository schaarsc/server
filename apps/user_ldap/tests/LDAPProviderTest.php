<?php
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\User_LDAP\Tests;

use OC\User\Manager;
use OCA\User_LDAP\Access;
use OCA\User_LDAP\Connection;
use OCA\User_LDAP\Group_LDAP;
use OCA\User_LDAP\IGroupLDAP;
use OCA\User_LDAP\IUserLDAP;
use OCA\User_LDAP\User_LDAP;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;

/**
 * Class LDAPProviderTest
 *
 * @group DB
 *
 * @package OCA\User_LDAP\Tests
 */
class LDAPProviderTest extends \Test\TestCase {
	protected function setUp(): void {
		parent::setUp();
	}

	private function getServerMock(IUserLDAP $userBackend, IGroupLDAP $groupBackend) {
		$server = $this->getMockBuilder('OC\Server')
			->setMethods(['getUserManager', 'getBackends', 'getGroupManager'])
			->setConstructorArgs(['', new \OC\Config(\OC::$configDir)])
			->getMock();
		$server->expects($this->any())
			->method('getUserManager')
			->willReturn($this->getUserManagerMock($userBackend));
		$server->expects($this->any())
			->method('getGroupManager')
			->willReturn($this->getGroupManagerMock($groupBackend));
		$server->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		return $server;
	}

	private function getUserManagerMock(IUserLDAP $userBackend) {
		$userManager = $this->getMockBuilder(Manager::class)
			->setMethods(['getBackends'])
			->setConstructorArgs([
				$this->createMock(IConfig::class),
				$this->createMock(ICacheFactory::class),
				$this->createMock(IEventDispatcher::class),
				$this->createMock(LoggerInterface::class),
			])
			->getMock();
		$userManager->expects($this->any())
			->method('getBackends')
			->willReturn([$userBackend]);
		return $userManager;
	}

	private function getGroupManagerMock(IGroupLDAP $groupBackend) {
		$groupManager = $this->getMockBuilder('OC\Group\Manager')
			->setMethods(['getBackends'])
			->disableOriginalConstructor()
			->getMock();
		$groupManager->expects($this->any())
			->method('getBackends')
			->willReturn([$groupBackend]);
		return $groupManager;
	}

	private function getDefaultGroupBackendMock() {
		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->disableOriginalConstructor()
			->getMock();

		return $groupBackend;
	}

	private function getLDAPProvider(IServerContainer $serverContainer) {
		$factory = new \OCA\User_LDAP\LDAPProviderFactory($serverContainer);
		return $factory->getLDAPProvider();
	}


	public function testGetUserDNUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())->method('userExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getUserDN('nonexisting_user');
	}

	public function testGetUserDN(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getLDAPAccess', 'username2dn'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->once())
			->method('userExists')
			->willReturn(true);
		$userBackend->expects($this->once())
			->method('username2dn')
			->willReturn('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org');
		$userBackend->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org',
			$ldapProvider->getUserDN('existing_user'));
	}


	public function testGetGroupDNGroupIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Group id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->disableOriginalConstructor()
			->getMock();

		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists'])
			->disableOriginalConstructor()
			->getMock();

		$groupBackend->expects($this->any())->method('groupExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getGroupDN('nonexisting_group');
	}

	public function testGetGroupDN(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getLDAPAccess', 'username2dn'])
			->disableOriginalConstructor()
			->getMock();

		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists', 'getLDAPAccess', 'groupname2dn'])
			->disableOriginalConstructor()
			->getMock();

		$groupBackend->expects($this->once())
			->method('groupExists')
			->willReturn(true);
		$groupBackend->expects($this->once())
			->method('groupname2dn')
			->willReturn('cn=existing_group,ou=Are Sufficient To,ou=Test,dc=example,dc=org');
		$groupBackend->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals('cn=existing_group,ou=Are Sufficient To,ou=Test,dc=example,dc=org',
			$ldapProvider->getGroupDN('existing_group'));
	}

	public function testGetUserName(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['dn2UserName'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())
			->method('dn2UserName')
			->willReturn('existing_user');

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals('existing_user',
			$ldapProvider->getUserName('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org'));
	}

	public function testDNasBaseParameter(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods([])
			->disableOriginalConstructor()
			->getMock();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$helper = new \OCA\User_LDAP\Helper(\OC::$server->getConfig(), \OC::$server->getDatabaseConnection());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals(
			$helper->DNasBaseParameter('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org'),
			$ldapProvider->DNasBaseParameter('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org'));
	}

	public function testSanitizeDN(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods([])
			->disableOriginalConstructor()
			->getMock();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$helper = new \OCA\User_LDAP\Helper(\OC::$server->getConfig(), \OC::$server->getDatabaseConnection());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals(
			$helper->sanitizeDN('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org'),
			$ldapProvider->sanitizeDN('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org'));
	}


	public function testGetLDAPConnectionUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())->method('userExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getLDAPConnection('nonexisting_user');
	}

	public function testGetLDAPConnection(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getNewLDAPConnection'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())
			->method('userExists')
			->willReturn(true);
		$ldapConnection = ldap_connect('ldap://example.com');
		$userBackend->expects($this->any())
			->method('getNewLDAPConnection')
			->willReturn($ldapConnection);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals($ldapConnection, $ldapProvider->getLDAPConnection('existing_user'));
	}


	public function testGetGroupLDAPConnectionGroupIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Group id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->disableOriginalConstructor()
			->getMock();

		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists'])
			->disableOriginalConstructor()
			->getMock();

		$groupBackend->expects($this->any())->method('groupExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getGroupLDAPConnection('nonexisting_group');
	}

	public function testGetGroupLDAPConnection(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->disableOriginalConstructor()
			->getMock();

		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists','getNewLDAPConnection'])
			->disableOriginalConstructor()
			->getMock();

		$groupBackend->expects($this->any())
			->method('groupExists')
			->willReturn(true);

		$ldapConnection = ldap_connect('ldap://example.com');
		$groupBackend->expects($this->any())
			->method('getNewLDAPConnection')
			->willReturn($ldapConnection);

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals($ldapConnection, $ldapProvider->getGroupLDAPConnection('existing_group'));
	}


	public function testGetLDAPBaseUsersUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())->method('userExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getLDAPBaseUsers('nonexisting_user');
	}

	public function testGetLDAPBaseUsers(): void {
		$bases = [
			'ou=users,ou=foobar,dc=example,dc=org',
			'ou=users,ou=barfoo,dc=example,dc=org',
		];
		$dn = 'uid=malik,' . $bases[1];

		$connection = $this->createMock(Connection::class);
		$connection->expects($this->any())
			->method('__get')
			->willReturnCallback(function ($key) use ($bases) {
				switch ($key) {
					case 'ldapBaseUsers':
						return $bases;
				}
				return null;
			});

		$access = $this->createMock(Access::class);
		$access->expects($this->any())
			->method('getConnection')
			->willReturn($connection);
		$access->expects($this->exactly(2))
			->method('isDNPartOfBase')
			->willReturnOnConsecutiveCalls(false, true);
		$access->expects($this->atLeastOnce())
			->method('username2dn')
			->willReturn($dn);

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getLDAPAccess', 'getConnection', 'getConfiguration'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->atLeastOnce())
			->method('userExists')
			->willReturn(true);
		$userBackend->expects($this->any())
			->method('getLDAPAccess')
			->willReturn($access);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals($bases[1], $ldapProvider->getLDAPBaseUsers('existing_user'));
	}


	public function testGetLDAPBaseGroupsUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())->method('userExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getLDAPBaseGroups('nonexisting_user');
	}

	public function testGetLDAPBaseGroups(): void {
		$bases = [
			'ou=groupd,ou=foobar,dc=example,dc=org',
			'ou=groups,ou=barfoo,dc=example,dc=org',
		];

		$connection = $this->createMock(Connection::class);
		$connection->expects($this->any())
			->method('__get')
			->willReturnCallback(function ($key) use ($bases) {
				switch ($key) {
					case 'ldapBaseGroups':
						return $bases;
				}
				return null;
			});

		$access = $this->createMock(Access::class);
		$access->expects($this->any())
			->method('getConnection')
			->willReturn($connection);

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getLDAPAccess', 'getConnection', 'getConfiguration'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())
			->method('userExists')
			->willReturn(true);
		$userBackend->expects($this->any())
			->method('getLDAPAccess')
			->willReturn($access);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals($bases[0], $ldapProvider->getLDAPBaseGroups('existing_user'));
	}


	public function testClearCacheUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())->method('userExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->clearCache('nonexisting_user');
	}

	public function testClearCache(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getLDAPAccess', 'getConnection', 'clearCache'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->once())
			->method('userExists')
			->willReturn(true);
		$userBackend->expects($this->once())
			->method('clearCache')
			->willReturn(true);
		$userBackend->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->clearCache('existing_user');
		$this->addToAssertionCount(1);
	}


	public function testClearGroupCacheGroupIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Group id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->disableOriginalConstructor()
			->getMock();
		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists'])
			->disableOriginalConstructor()
			->getMock();
		$groupBackend->expects($this->any())->method('groupExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->clearGroupCache('nonexisting_group');
	}

	public function testClearGroupCache(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->disableOriginalConstructor()
			->getMock();
		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists', 'getLDAPAccess', 'getConnection', 'clearCache'])
			->disableOriginalConstructor()
			->getMock();
		$groupBackend->expects($this->once())
			->method('groupExists')
			->willReturn(true);
		$groupBackend->expects($this->once())
			->method('clearCache')
			->willReturn(true);
		$groupBackend->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->clearGroupCache('existing_group');
		$this->addToAssertionCount(1);
	}

	public function testDnExists(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['dn2UserName'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())
			->method('dn2UserName')
			->willReturn('existing_user');

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertTrue($ldapProvider->dnExists('cn=existing_user,ou=Are Sufficient To,ou=Test,dc=example,dc=org'));
	}

	public function testFlagRecord(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods([])
			->disableOriginalConstructor()
			->getMock();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->flagRecord('existing_user');
		$this->addToAssertionCount(1);
	}

	public function testUnflagRecord(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods([])
			->disableOriginalConstructor()
			->getMock();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->unflagRecord('existing_user');
		$this->addToAssertionCount(1);
	}


	public function testGetLDAPDisplayNameFieldUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())->method('userExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getLDAPDisplayNameField('nonexisting_user');
	}

	public function testGetLDAPDisplayNameField(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getLDAPAccess', 'getConnection', 'getConfiguration'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->once())
			->method('userExists')
			->willReturn(true);
		$userBackend->expects($this->once())
			->method('getConfiguration')
			->willReturn(['ldap_display_name' => 'displayName']);
		$userBackend->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals('displayName', $ldapProvider->getLDAPDisplayNameField('existing_user'));
	}


	public function testGetLDAPEmailFieldUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->any())->method('userExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getLDAPEmailField('nonexisting_user');
	}

	public function testGetLDAPEmailField(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->setMethods(['userExists', 'getLDAPAccess', 'getConnection', 'getConfiguration'])
			->disableOriginalConstructor()
			->getMock();
		$userBackend->expects($this->once())
			->method('userExists')
			->willReturn(true);
		$userBackend->expects($this->once())
			->method('getConfiguration')
			->willReturn(['ldap_email_attr' => 'mail']);
		$userBackend->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		$server = $this->getServerMock($userBackend, $this->getDefaultGroupBackendMock());

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals('mail', $ldapProvider->getLDAPEmailField('existing_user'));
	}


	public function testGetLDAPGroupMemberAssocUserIDNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Group id not found in LDAP');

		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->disableOriginalConstructor()
			->getMock();

		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists'])
			->disableOriginalConstructor()
			->getMock();

		$groupBackend->expects($this->any())->method('groupExists')->willReturn(false);

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getLDAPGroupMemberAssoc('nonexisting_group');
	}

	public function testgetLDAPGroupMemberAssoc(): void {
		$userBackend = $this->getMockBuilder('OCA\User_LDAP\User_LDAP')
			->disableOriginalConstructor()
			->getMock();

		$groupBackend = $this->getMockBuilder('OCA\User_LDAP\Group_LDAP')
			->setMethods(['groupExists', 'getLDAPAccess', 'getConnection', 'getConfiguration'])
			->disableOriginalConstructor()
			->getMock();

		$groupBackend->expects($this->once())
			->method('groupExists')
			->willReturn(true);
		$groupBackend->expects($this->any())
			->method('getConfiguration')
			->willReturn(['ldap_group_member_assoc_attribute' => 'assoc_type']);
		$groupBackend->expects($this->any())
			->method($this->anything())
			->willReturnSelf();

		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$this->assertEquals('assoc_type', $ldapProvider->getLDAPGroupMemberAssoc('existing_group'));
	}

	public function testGetMultiValueUserAttributeUserNotFound(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User id not found in LDAP');

		$userBackend = $this->createMock(User_LDAP::class);
		$userBackend->expects(self::once())
			->method('userExists')
			->with('admin')
			->willReturn(false);
		$groupBackend = $this->createMock(Group_LDAP::class);
		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getMultiValueUserAttribute('admin', 'mailAlias');
	}

	public function testGetMultiValueUserAttributeCacheHit(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())
			->method('getFromCache')
			->with('admin-mailAlias')
			->willReturn(['aliasA@test.local', 'aliasB@test.local']);
		$access = $this->createMock(Access::class);
		$access->expects(self::once())
			->method('getConnection')
			->willReturn($connection);
		$userBackend = $this->createMock(User_LDAP::class);
		$userBackend->expects(self::once())
			->method('userExists')
			->with('admin')
			->willReturn(true);
		$userBackend->expects(self::once())
			->method('getLDAPAccess')
			->willReturn($access);
		$groupBackend = $this->createMock(Group_LDAP::class);
		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$ldapProvider->getMultiValueUserAttribute('admin', 'mailAlias');
	}

	public function testGetMultiValueUserAttributeLdapError(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())
			->method('getFromCache')
			->with('admin-mailAlias')
			->willReturn(null);
		$access = $this->createMock(Access::class);
		$access->expects(self::once())
			->method('getConnection')
			->willReturn($connection);
		$access->expects(self::once())
			->method('username2dn')
			->with('admin')
			->willReturn('admin');
		$access->expects(self::once())
			->method('readAttribute')
			->with('admin', 'mailAlias')
			->willReturn(false);
		$userBackend = $this->getMockBuilder(User_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$userBackend->method('userExists')
			->with('admin')
			->willReturn(true);
		$userBackend->method('getLDAPAccess')
			->willReturn($access);
		$groupBackend = $this->getMockBuilder(Group_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$values = $ldapProvider->getMultiValueUserAttribute('admin', 'mailAlias');

		self::assertCount(0, $values);
	}

	public function testGetMultiValueUserAttribute(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())
			->method('getFromCache')
			->with('admin-mailAlias')
			->willReturn(null);
		$access = $this->createMock(Access::class);
		$access->expects(self::once())
			->method('getConnection')
			->willReturn($connection);
		$access->expects(self::once())
			->method('username2dn')
			->with('admin')
			->willReturn('admin');
		$access->expects(self::once())
			->method('readAttribute')
			->with('admin', 'mailAlias')
			->willReturn(['aliasA@test.local', 'aliasB@test.local']);
		$userBackend = $this->getMockBuilder(User_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$userBackend->method('userExists')
			->with('admin')
			->willReturn(true);
		$userBackend->method('getLDAPAccess')
			->willReturn($access);
		$groupBackend = $this->getMockBuilder(Group_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$values = $ldapProvider->getMultiValueUserAttribute('admin', 'mailAlias');

		self::assertCount(2, $values);
	}

	public function testGetUserAttributeLdapError(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())
			->method('getFromCache')
			->with('admin-mailAlias')
			->willReturn(null);
		$access = $this->createMock(Access::class);
		$access->expects(self::once())
			->method('getConnection')
			->willReturn($connection);
		$access->expects(self::once())
			->method('username2dn')
			->with('admin')
			->willReturn('admin');
		$access->expects(self::once())
			->method('readAttribute')
			->with('admin', 'mailAlias')
			->willReturn(false);
		$userBackend = $this->getMockBuilder(User_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$userBackend->method('userExists')
			->with('admin')
			->willReturn(true);
		$userBackend->method('getLDAPAccess')
			->willReturn($access);
		$groupBackend = $this->getMockBuilder(Group_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$value = $ldapProvider->getUserAttribute('admin', 'mailAlias');

		self::assertNull($value);
	}

	public function testGetUserAttribute(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())
			->method('getFromCache')
			->with('admin-mailAlias')
			->willReturn(null);
		$access = $this->createMock(Access::class);
		$access->expects(self::once())
			->method('getConnection')
			->willReturn($connection);
		$access->expects(self::once())
			->method('username2dn')
			->with('admin')
			->willReturn('admin');
		$access->expects(self::once())
			->method('readAttribute')
			->with('admin', 'mailAlias')
			->willReturn(['aliasA@test.local', 'aliasB@test.local']);
		$userBackend = $this->getMockBuilder(User_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$userBackend->method('userExists')
			->with('admin')
			->willReturn(true);
		$userBackend->method('getLDAPAccess')
			->willReturn($access);
		$groupBackend = $this->getMockBuilder(Group_LDAP::class)
			->disableOriginalConstructor()
			->getMock();
		$server = $this->getServerMock($userBackend, $groupBackend);

		$ldapProvider = $this->getLDAPProvider($server);
		$value = $ldapProvider->getUserAttribute('admin', 'mailAlias');

		self::assertEquals('aliasA@test.local', $value);
	}
}
