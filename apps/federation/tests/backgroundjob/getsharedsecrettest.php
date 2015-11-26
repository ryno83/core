<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OCA\Federation\Tests\BackgroundJob;


use OCA\Federation\BackgroundJob\GetSharedSecret;
use OCA\Files_Sharing\Tests\TestCase;
use OCA\Federation\DbHandler;
use OCA\Federation\TrustedServers;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\ILogger;
use OCP\IURLGenerator;

class GetSharedSecretTest extends TestCase {

	/** @var \PHPUnit_Framework_MockObject_MockObject | IClient */
	private $httpClient;

	/** @var \PHPUnit_Framework_MockObject_MockObject | IJobList */
	private $jobList;

	/** @var \PHPUnit_Framework_MockObject_MockObject | IURLGenerator */
	private $urlGenerator;

	/** @var \PHPUnit_Framework_MockObject_MockObject | TrustedServers  */
	private $trustedServers;

	/** @var \PHPUnit_Framework_MockObject_MockObject | DbHandler */
	private $dbHandler;

	/** @var \PHPUnit_Framework_MockObject_MockObject | ILogger */
	private $logger;

	/** @var \PHPUnit_Framework_MockObject_MockObject | IResponse */
	private $response;

	/** @var GetSharedSecret */
	private $getSharedSecret;

	public function setUp() {
		parent::setUp();

		$this->httpClient = $this->getMock('OCP\Http\Client\IClient');
		$this->jobList = $this->getMock('OCP\BackgroundJob\IJobList');
		$this->urlGenerator = $this->getMock('OCP\IURLGenerator');
		$this->trustedServers = $this->getMockBuilder('OCA\Federation\TrustedServers')
			->disableOriginalConstructor()->getMock();
		$this->dbHandler = $this->getMockBuilder('OCA\Federation\DbHandler')
			->disableOriginalConstructor()->getMock();
		$this->logger = $this->getMock('OCP\ILogger');
		$this->response = $this->getMock('OCP\Http\Client\IResponse');

		$this->getSharedSecret = new GetSharedSecret(
			$this->httpClient,
			$this->urlGenerator,
			$this->jobList,
			$this->trustedServers,
			$this->logger,
			$this->dbHandler
		);
	}

	/**
	 * @dataProvider dataTestExecute
	 *
	 * @param bool $isTrustedServer
	 */
	public function testExecute($isTrustedServer) {
		/** @var GetSharedSecret |\PHPUnit_Framework_MockObject_MockObject $getSharedSecret */
		$getSharedSecret = $this->getMockBuilder('OCA\Federation\BackgroundJob\GetSharedSecret')
			->setConstructorArgs(
				[
					$this->httpClient,
					$this->urlGenerator,
					$this->jobList,
					$this->trustedServers,
					$this->logger,
					$this->dbHandler
				]
			)->setMethods(['parentExecute'])->getMock();
		$this->invokePrivate($getSharedSecret, 'argument', [['url' => 'url']]);

		$this->jobList->expects($this->once())->method('remove');
		$this->trustedServers->expects($this->once())->method('isTrustedServer')
			->with('url')->willReturn($isTrustedServer);
		if ($isTrustedServer) {
			$getSharedSecret->expects($this->once())->method('parentExecute');
		} else {
			$getSharedSecret->expects($this->never())->method('parentExecute');
		}

		$getSharedSecret->execute($this->jobList);

	}

	public function dataTestExecute() {
		return [
			[true],
			[false]
		];
	}

	/**
	 * @dataProvider dataTestRun
	 *
	 * @param int $statusCode
	 */
	public function testRun($statusCode) {

		$target = 'targetURL';
		$source = 'sourceURL';
		$token = 'token';

		$argument = ['url' => $target, 'token' => $token];

		$this->urlGenerator->expects($this->once())->method('getAbsoluteURL')->with('/')
			->willReturn($source);
		$this->httpClient->expects($this->once())->method('get')
			->with(
				$target . '/ocs/v2.php/apps/federation/api/v1/shared-secret?format=json',
				[
					'query' =>
						[
							'url' => $source,
							'token' => $token
						],
					'timeout' => 3,
					'connect_timeout' => 3,
				]
			)->willReturn($this->response);

		$this->response->expects($this->once())->method('getStatusCode')
			->willReturn($statusCode);

		if (
			$statusCode !== Http::STATUS_OK
			&& $statusCode !== Http::STATUS_FORBIDDEN
		) {
			$this->jobList->expects($this->once())->method('add')
				->with('OCA\Federation\BackgroundJob\GetSharedSecret', $argument);
			$this->dbHandler->expects($this->never())->method('addToken');
		}  else {
			$this->dbHandler->expects($this->once())->method('addToken')->with($target, '');
			$this->jobList->expects($this->never())->method('add');
		}

		if ($statusCode === Http::STATUS_OK) {
			$this->response->expects($this->once())->method('getBody')
				->willReturn('{"ocs":{"data":{"sharedSecret":"secret"}}}');
			$this->trustedServers->expects($this->once())->method('addSharedSecret')
				->with($target, 'secret');
		} else {
			$this->trustedServers->expects($this->never())->method('addSharedSecret');
		}

		$this->invokePrivate($this->getSharedSecret, 'run', [$argument]);
	}

	public function dataTestRun() {
		return [
			[Http::STATUS_OK],
			[Http::STATUS_FORBIDDEN],
			[Http::STATUS_CONFLICT],
		];
	}

}
