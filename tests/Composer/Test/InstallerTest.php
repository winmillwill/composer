<?php
/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test;

use Composer\Installer;
use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\RepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\Mock\InstalledFilesystemRepositoryMock;
use Composer\Test\Mock\InstallationManagerMock;
use Composer\Test\Mock\WritableRepositoryMock;

class InstallerTest extends TestCase
{
    /**
     * @dataProvider provideInstaller
     */
    public function testInstaller(PackageInterface $rootPackage, $repositories, array $options)
    {
        $io = $this->getMock('Composer\IO\IOInterface');

        $downloadManager = $this->getMock('Composer\Downloader\DownloadManager');
        $config = $this->getMock('Composer\Config');

        $repositoryManager = new RepositoryManager($io, $config);
        $repositoryManager->setLocalRepository(new WritableRepositoryMock());
        $repositoryManager->setLocalDevRepository(new WritableRepositoryMock());

        if (!is_array($repositories)) {
            $repositories = array($repositories);
        }
        foreach ($repositories as $repository) {
            $repositoryManager->addRepository($repository);
        }

        $locker = $this->getMockBuilder('Composer\Package\Locker')->disableOriginalConstructor()->getMock();
        $installationManager = new InstallationManagerMock();
        $eventDispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMock('Composer\Autoload\AutoloadGenerator');

        $installer = new Installer($io, clone $rootPackage, $downloadManager, $repositoryManager, $locker, $installationManager, $eventDispatcher, $autoloadGenerator);
        $result = $installer->run();
        $this->assertTrue($result);

        $expectedInstalled   = isset($options['install']) ? $options['install'] : array();
        $expectedUpdated     = isset($options['update']) ? $options['update'] : array();
        $expectedUninstalled = isset($options['uninstall']) ? $options['uninstall'] : array();

        $installed = $installationManager->getInstalledPackages();
        $this->assertSame($expectedInstalled, $installed);

        $updated = $installationManager->getUpdatedPackages();
        $this->assertSame($expectedUpdated, $updated);

        $uninstalled = $installationManager->getUninstalledPackages();
        $this->assertSame($expectedUninstalled, $uninstalled);
    }

    public function provideInstaller()
    {
        $cases = array();

        // when A requires B and B requires A, and A is a non-published root package
        // the install of B should succeed

        $a = $this->getPackage('A', '1.0.0');
        $a->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('=', '1.0.0')),
        ));
        $b = $this->getPackage('B', '1.0.0');
        $b->setRequires(array(
            new Link('B', 'A', $this->getVersionConstraint('=', '1.0.0')),
        ));

        $cases[] = array(
            $a,
            new ArrayRepository(array($b)),
            array(
                'install' => array($b)
            ),
        );

        // #480: when A requires B and B requires A, and A is a published root package
        // only B should be installed, as A is the root

        $a = $this->getPackage('A', '1.0.0');
        $a->setRequires(array(
            new Link('A', 'B', $this->getVersionConstraint('=', '1.0.0')),
        ));
        $b = $this->getPackage('B', '1.0.0');
        $b->setRequires(array(
            new Link('B', 'A', $this->getVersionConstraint('=', '1.0.0')),
        ));

        $cases[] = array(
            $a,
            new ArrayRepository(array($a, $b)),
            array(
                'install' => array($b)
            ),
        );

        return $cases;
    }

    /**
     * @dataProvider getIntegrationTests
     */
    public function testIntegration($file, $message, $condition, $composer, $lock, $installed, $installedDev, $update, $dev, $expect)
    {
        if ($condition) {
            eval('$res = '.$condition.';');
            if (!$res) {
                $this->markTestSkipped($condition);
            }
        }

        $io = $this->getMock('Composer\IO\IOInterface');

        $composer = FactoryMock::create($io, $composer);

        $jsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $jsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($installed));
        $jsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));

        $devJsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $devJsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($installedDev));
        $devJsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));

        $repositoryManager = $composer->getRepositoryManager();
        $repositoryManager->setLocalRepository(new InstalledFilesystemRepositoryMock($jsonMock));
        $repositoryManager->setLocalDevRepository(new InstalledFilesystemRepositoryMock($devJsonMock));

        $lockJsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $lockJsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($lock));

        $locker = new Locker($lockJsonMock, $repositoryManager, isset($lock['hash']) ? $lock['hash'] : '');
        $composer->setLocker($locker);

        $autoloadGenerator = $this->getMock('Composer\Autoload\AutoloadGenerator');

        $installer = Installer::create(
            $io,
            $composer,
            null,
            $autoloadGenerator
        );

        $installer->setDevMode($dev)->setUpdate($update);

        $result = $installer->run();
        $this->assertTrue($result);

        $expectedInstalled   = isset($options['install']) ? $options['install'] : array();
        $expectedUpdated     = isset($options['update']) ? $options['update'] : array();
        $expectedUninstalled = isset($options['uninstall']) ? $options['uninstall'] : array();

        $installationManager = $composer->getInstallationManager();
        $this->assertSame($expect, implode("\n", $installationManager->getTrace()));
    }

    public function getIntegrationTests()
    {
        $fixturesDir = realpath(__DIR__.'/Fixtures/installer/');
        $tests = array();

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixturesDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match('/\.test$/', $file)) {
                continue;
            }

            $test = file_get_contents($file->getRealpath());

            $content = '(?:.(?!--[A-Z]))+';
            $pattern = '{^
                --TEST--\s*(?P<test>.*?)\s*
                (?:--CONDITION--\s*(?P<condition>'.$content.'))?\s*
                --COMPOSER--\s*(?P<composer>'.$content.')\s*
                (?:--LOCK--\s*(?P<lock>'.$content.'))?\s*
                (?:--INSTALLED--\s*(?P<installed>'.$content.'))?\s*
                (?:--INSTALLED:DEV--\s*(?P<installedDev>'.$content.'))?\s*
                --EXPECT(?P<update>:UPDATE)?(?P<dev>:DEV)?--\s*(?P<expect>.*?)\s*
            $}xs';

            $installed = array();
            $installedDev = array();
            $lock = array();

            if (preg_match($pattern, $test, $match)) {
                try {
                    $message = $match['test'];
                    $condition = !empty($match['condition']) ? $match['condition'] : null;
                    $composer = JsonFile::parseJson($match['composer']);
                    if (!empty($match['lock'])) {
                        $lock = JsonFile::parseJson($match['lock']);
                    }
                    if (!empty($match['installed'])) {
                        $installed = JsonFile::parseJson($match['installed']);
                    }
                    if (!empty($match['installedDev'])) {
                        $installedDev = JsonFile::parseJson($match['installedDev']);
                    }
                    $update = !empty($match['update']);
                    $dev = !empty($match['dev']);
                    $expect = $match['expect'];
                } catch (\Exception $e) {
                    die(sprintf('Test "%s" is not valid: '.$e->getMessage(), str_replace($fixturesDir.'/', '', $file)));
                }
            } else {
                die(sprintf('Test "%s" is not valid, did not match the expected format.', str_replace($fixturesDir.'/', '', $file)));
            }

            $tests[] = array(str_replace($fixturesDir.'/', '', $file), $message, $condition, $composer, $lock, $installed, $installedDev, $update, $dev, $expect);
        }

        return $tests;
    }
}