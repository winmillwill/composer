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

namespace Composer\Test\Autoload;

use Composer\Autoload\AutoloadGenerator;
use Composer\Package\Link;
use Composer\Util\Filesystem;
use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Test\TestCase;
use Composer\Script\ScriptEvents;

class AutoloadGeneratorTest extends TestCase
{
    public $vendorDir;
    private $config;
    private $workingDir;
    private $im;
    private $repository;
    private $generator;
    private $fs;
    private $eventDispatcher;

    protected function setUp()
    {
        $this->fs = new Filesystem;
        $that = $this;

        $this->workingDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cmptest-'.md5(uniqid('', true));
        $this->fs->ensureDirectoryExists($this->workingDir);
        $this->vendorDir = $this->workingDir.DIRECTORY_SEPARATOR.'composer-test-autoload';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->config = $this->getMock('Composer\Config');

        $this->config->expects($this->at(0))
            ->method('get')
            ->with($this->equalTo('vendor-dir'))
            ->will($this->returnCallback(function () use ($that) {
                return $that->vendorDir;
            }));

        $this->config->expects($this->at(1))
            ->method('get')
            ->with($this->equalTo('vendor-dir'))
            ->will($this->returnCallback(function () use ($that) {
                return $that->vendorDir;
            }));

        $this->origDir = getcwd();
        chdir($this->workingDir);

        $this->im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function ($package) use ($that) {
                $targetDir = $package->getTargetDir();

                return $that->vendorDir.'/'.$package->getName() . ($targetDir ? '/'.$targetDir : '');
            }));
        $this->repository = $this->getMock('Composer\Repository\InstalledRepositoryInterface');

        $this->eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $this->generator = new AutoloadGenerator($this->eventDispatcher);
    }

    protected function tearDown()
    {
        chdir($this->origDir);

        if (is_dir($this->workingDir)) {
            $this->fs->removeDirectory($this->workingDir);
        }
        if (is_dir($this->vendorDir)) {
            $this->fs->removeDirectory($this->vendorDir);
        }
    }

    public function testMainPackageAutoloading()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main' => 'src/', 'Lala' => array('src/', 'lib/')),
            'classmap' => array('composersrc/'),
        ));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->fs->ensureDirectoryExists($this->workingDir.'/composer');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib');

        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc');
        file_put_contents($this->workingDir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_1');
        $this->assertAutoloadFiles('main', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap', $this->vendorDir.'/composer', 'classmap');
    }

    public function testVendorDirSameAsWorkingDir()
    {
        $this->vendorDir = $this->workingDir;

        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main' => 'src/', 'Lala' => 'src/'),
            'classmap' => array('composersrc/'),
        ));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/src/Main');
        file_put_contents($this->vendorDir.'/src/Main/Foo.php', '<?php namespace Main; class Foo {}');

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composersrc');
        file_put_contents($this->vendorDir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_2');
        $this->assertAutoloadFiles('main3', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap3', $this->vendorDir.'/composer', 'classmap');
    }

    public function testMainPackageAutoloadingAlternativeVendorDir()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main' => 'src/', 'Lala' => 'src/'),
            'classmap' => array('composersrc/'),
        ));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->vendorDir .= '/subdir';

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src');

        $this->fs->ensureDirectoryExists($this->workingDir.'/composersrc');
        file_put_contents($this->workingDir.'/composersrc/foo.php', '<?php class ClassMapFoo {}');
        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_3');
        $this->assertAutoloadFiles('main2', $this->vendorDir.'/composer');
        $this->assertAutoloadFiles('classmap2', $this->vendorDir.'/composer', 'classmap');
    }

    public function testMainPackageAutoloadingWithTargetDir()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main\\Foo' => '', 'Main\\Bar' => ''),
            'classmap' => array('Main/Foo/src', 'lib'),
            'files' => array('foo.php', 'Main/Foo/bar.php'),
        ));
        $package->setTargetDir('Main/Foo/');

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src');
        $this->fs->ensureDirectoryExists($this->workingDir.'/lib');

        file_put_contents($this->workingDir.'/src/rootfoo.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->workingDir.'/lib/rootbar.php', '<?php class ClassMapBar {}');
        file_put_contents($this->workingDir.'/foo.php', '<?php class FilesFoo {}');
        file_put_contents($this->workingDir.'/bar.php', '<?php class FilesBar {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'TargetDir');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_target_dir.php', $this->vendorDir.'/autoload.php');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_real_target_dir.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_files_target_dir.php', $this->vendorDir.'/composer/autoload_files.php');
        $this->assertAutoloadFiles('classmap6', $this->vendorDir.'/composer', 'classmap');
    }

    public function testVendorsAutoloading()
    {
        $package = new Package('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new AliasPackage($b, '1.2', '1.2');
        $a->setAutoload(array('psr-0' => array('A' => 'src/', 'A\\B' => 'lib/')));
        $b->setAutoload(array('psr-0' => array('B\\Sub\\Name' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/lib');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_5');
        $this->assertAutoloadFiles('vendors', $this->vendorDir.'/composer');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated, even if empty.");
    }

    public function testPSR0ToClassMapIgnoresNonExistingDir()
    {
        $package = new Package('a', '1.0', '1.0');

        $package->setAutoload(array('psr-0' => array('foo/bar/non/existing/')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_8');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated.");
        $this->assertEquals(
            array(),
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
    }

    public function testVendorsClassMapAutoloading()
    {
        $package = new Package('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload(array('classmap' => array('src/')));
        $b->setAutoload(array('classmap' => array('src/', 'lib/')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/lib');
        file_put_contents($this->vendorDir.'/a/a/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/src/b.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/b/b/lib/c.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_6');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated.");
        $this->assertEquals(
            array(
                'ClassMapBar' => $this->vendorDir.'/b/b/src/b.php',
                'ClassMapBaz' => $this->vendorDir.'/b/b/lib/c.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/src/a.php',
            ),
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
        $this->assertAutoloadFiles('classmap4', $this->vendorDir.'/composer', 'classmap');
    }

    public function testVendorsClassMapAutoloadingWithTargetDir()
    {
        $package = new Package('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload(array('classmap' => array('target/src/', 'lib/')));
        $a->setTargetDir('target');
        $b->setAutoload(array('classmap' => array('src/')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/target/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/target/lib');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');
        file_put_contents($this->vendorDir.'/a/a/target/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/a/a/target/lib/b.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/b/b/src/c.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_6');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated.");
        $this->assertEquals(
            array(
                'ClassMapBar' => $this->vendorDir.'/a/a/target/lib/b.php',
                'ClassMapBaz' => $this->vendorDir.'/b/b/src/c.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/target/src/a.php',
            ),
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
    }

    public function testClassMapAutoloadingEmptyDirAndExactFile()
    {
        $package = new Package('a', '1.0', '1.0');

        $packages = array();
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new Package('c/c', '1.0', '1.0');
        $a->setAutoload(array('classmap' => array('')));
        $b->setAutoload(array('classmap' => array('test.php')));
        $c->setAutoload(array('classmap' => array('./')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/foo');
        file_put_contents($this->vendorDir.'/a/a/src/a.php', '<?php class ClassMapFoo {}');
        file_put_contents($this->vendorDir.'/b/b/test.php', '<?php class ClassMapBar {}');
        file_put_contents($this->vendorDir.'/c/c/foo/test.php', '<?php class ClassMapBaz {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, '_7');
        $this->assertTrue(file_exists($this->vendorDir.'/composer/autoload_classmap.php'), "ClassMap file needs to be generated.");
        $this->assertEquals(
            array(
                'ClassMapBar' => $this->vendorDir.'/b/b/test.php',
                'ClassMapBaz' => $this->vendorDir.'/c/c/foo/test.php',
                'ClassMapFoo' => $this->vendorDir.'/a/a/src/a.php',
            ),
            include $this->vendorDir.'/composer/autoload_classmap.php'
        );
        $this->assertAutoloadFiles('classmap5', $this->vendorDir.'/composer', 'classmap');
    }

    public function testFilesAutoloadGeneration()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array('files' => array('root.php')));

        $packages = array();
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $packages[] = $c = new Package('c/c', '1.0', '1.0');
        $a->setAutoload(array('files' => array('test.php')));
        $b->setAutoload(array('files' => array('test2.php')));
        $c->setAutoload(array('files' => array('test3.php', 'foo/bar/test4.php')));
        $c->setTargetDir('foo/bar');

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/c/c/foo/bar');
        file_put_contents($this->vendorDir.'/a/a/test.php', '<?php function testFilesAutoloadGeneration1() {}');
        file_put_contents($this->vendorDir.'/b/b/test2.php', '<?php function testFilesAutoloadGeneration2() {}');
        file_put_contents($this->vendorDir.'/c/c/foo/bar/test3.php', '<?php function testFilesAutoloadGeneration3() {}');
        file_put_contents($this->vendorDir.'/c/c/foo/bar/test4.php', '<?php function testFilesAutoloadGeneration4() {}');
        file_put_contents($this->workingDir.'/root.php', '<?php function testFilesAutoloadGenerationRoot() {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'FilesAutoload');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_functions.php', $this->vendorDir.'/autoload.php');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_real_functions.php', $this->vendorDir.'/composer/autoload_real.php');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_files_functions.php', $this->vendorDir.'/composer/autoload_files.php');

        include $this->vendorDir . '/autoload.php';
        $this->assertTrue(function_exists('testFilesAutoloadGeneration1'));
        $this->assertTrue(function_exists('testFilesAutoloadGeneration2'));
        $this->assertTrue(function_exists('testFilesAutoloadGeneration3'));
        $this->assertTrue(function_exists('testFilesAutoloadGeneration4'));
        $this->assertTrue(function_exists('testFilesAutoloadGenerationRoot'));
    }

    public function testFilesAutoloadOrderByDependencies()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array('files' => array('root.php')));
        $package->setRequires(array(new Link('a', 'z/foo')));
        $package->setRequires(array(new Link('a', 'd/d')));
        $package->setRequires(array(new Link('a', 'e/e')));

        $packages = array();
        $packages[] = $z = new Package('z/foo', '1.0', '1.0');
        $packages[] = $b = new Package('b/bar', '1.0', '1.0');
        $packages[] = $d = new Package('d/d', '1.0', '1.0');
        $packages[] = $c = new Package('c/lorem', '1.0', '1.0');
        $packages[] = $e = new Package('e/e', '1.0', '1.0');

        $z->setAutoload(array('files' => array('testA.php')));
        $z->setRequires(array(new Link('z/foo', 'c/lorem')));

        $b->setAutoload(array('files' => array('testB.php')));
        $b->setRequires(array(new Link('b/bar', 'c/lorem'), new Link('b/bar', 'd/d')));

        $c->setAutoload(array('files' => array('testC.php')));

        $d->setAutoload(array('files' => array('testD.php')));
        $d->setRequires(array(new Link('d/d', 'c/lorem')));

        $e->setAutoload(array('files' => array('testE.php')));
        $e->setRequires(array(new Link('e/e', 'c/lorem')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir . '/z/foo');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/b/bar');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/c/lorem');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/d/d');
        $this->fs->ensureDirectoryExists($this->vendorDir . '/e/e');
        file_put_contents($this->vendorDir . '/z/foo/testA.php', '<?php function testFilesAutoloadOrderByDependency1() {}');
        file_put_contents($this->vendorDir . '/b/bar/testB.php', '<?php function testFilesAutoloadOrderByDependency2() {}');
        file_put_contents($this->vendorDir . '/c/lorem/testC.php', '<?php function testFilesAutoloadOrderByDependency3() {}');
        file_put_contents($this->vendorDir . '/d/d/testD.php', '<?php function testFilesAutoloadOrderByDependency4() {}');
        file_put_contents($this->vendorDir . '/e/e/testE.php', '<?php function testFilesAutoloadOrderByDependency5() {}');
        file_put_contents($this->workingDir . '/root.php', '<?php function testFilesAutoloadOrderByDependencyRoot() {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'FilesAutoloadOrder');
        $this->assertFileEquals(__DIR__ . '/Fixtures/autoload_functions_by_dependency.php', $this->vendorDir . '/autoload.php');
        $this->assertFileEquals(__DIR__ . '/Fixtures/autoload_real_files_by_dependency.php', $this->vendorDir . '/composer/autoload_real.php');

        require $this->vendorDir . '/autoload.php';

        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency1'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency2'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency3'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency4'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependency5'));
        $this->assertTrue(function_exists('testFilesAutoloadOrderByDependencyRoot'));
    }

    public function testOverrideVendorsAutoloading()
    {
        $package = new Package('z', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('A\\B' => $this->workingDir.'/lib'), 'classmap' => array($this->workingDir.'/src')));
        $package->setRequires(array(new Link('z', 'a/a')));

        $packages = array();
        $packages[] = $a = new Package('a/a', '1.0', '1.0');
        $packages[] = $b = new Package('b/b', '1.0', '1.0');
        $a->setAutoload(array('psr-0' => array('A' => 'src/', 'A\\B' => 'lib/'), 'classmap' => array('classmap')));
        $b->setAutoload(array('psr-0' => array('B\\Sub\\Name' => 'src/')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->workingDir.'/lib/A/B');
        $this->fs->ensureDirectoryExists($this->workingDir.'/src/');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/classmap');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/src');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/a/a/lib/A/B');
        $this->fs->ensureDirectoryExists($this->vendorDir.'/b/b/src');
        file_put_contents($this->workingDir.'/lib/A/B/C.php', '<?php namespace A\\B; class C {}');
        file_put_contents($this->workingDir.'/src/classes.php', '<?php namespace Foo; class Bar {}');
        file_put_contents($this->vendorDir.'/a/a/lib/A/B/C.php', '<?php namespace A\\B; class C {}');
        file_put_contents($this->vendorDir.'/a/a/classmap/classes.php', '<?php namespace Foo; class Bar {}');

        $expectedNamespace = <<<EOF
<?php

// autoload_namespaces.php @generated by Composer

\$vendorDir = dirname(dirname(__FILE__));
\$baseDir = dirname(\$vendorDir);

return array(
    'B\\\\Sub\\\\Name' => array(\$vendorDir . '/b/b/src'),
    'A\\\\B' => array(\$baseDir . '/lib', \$vendorDir . '/a/a/lib'),
    'A' => array(\$vendorDir . '/a/a/src'),
);

EOF;

        $expectedClassmap = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = dirname(dirname(__FILE__));
\$baseDir = dirname(\$vendorDir);

return array(
    'A\\\\B\\\\C' => \$baseDir . '/lib/A/B/C.php',
    'Foo\\\\Bar' => \$baseDir . '/src/classes.php',
);

EOF;

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_9');
        $this->assertEquals($expectedNamespace, file_get_contents($this->vendorDir.'/composer/autoload_namespaces.php'));
        $this->assertEquals($expectedClassmap, file_get_contents($this->vendorDir.'/composer/autoload_classmap.php'));
    }

    public function testIncludePathFileGeneration()
    {
        $package = new Package('a', '1.0', '1.0');
        $packages = array();

        $a = new Package("a/a", "1.0", "1.0");
        $a->setIncludePaths(array("lib/"));

        $b = new Package("b/b", "1.0", "1.0");
        $b->setIncludePaths(array("library"));

        $c = new Package("c", "1.0", "1.0");
        $c->setIncludePaths(array("library"));

        $packages[] = $a;
        $packages[] = $b;
        $packages[] = $c;

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/composer');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_10');

        $this->assertFileEquals(__DIR__.'/Fixtures/include_paths.php', $this->vendorDir.'/composer/include_paths.php');
        $this->assertEquals(
            array(
                $this->vendorDir."/a/a/lib",
                $this->vendorDir."/b/b/library",
                $this->vendorDir."/c/library",
            ),
            require $this->vendorDir."/composer/include_paths.php"
        );
    }

    public function testIncludePathsArePrependedInAutoloadFile()
    {
        $package = new Package('a', '1.0', '1.0');
        $packages = array();

        $a = new Package("a/a", "1.0", "1.0");
        $a->setIncludePaths(array("lib/"));

        $packages[] = $a;

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_11');

        $oldIncludePath = get_include_path();

        require $this->vendorDir."/autoload.php";

        $this->assertEquals(
            $this->vendorDir."/a/a/lib".PATH_SEPARATOR.$oldIncludePath,
            get_include_path()
        );

        set_include_path($oldIncludePath);
    }

    public function testIncludePathsInMainPackage()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setIncludePaths(array('/lib', '/src'));

        $packages = array($a = new Package("a/a", "1.0", "1.0"));
        $a->setIncludePaths(array("lib/"));

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_12');

        $oldIncludePath = get_include_path();

        require $this->vendorDir."/autoload.php";

        $this->assertEquals(
            $this->workingDir."/lib".PATH_SEPARATOR.$this->workingDir."/src".PATH_SEPARATOR.$this->vendorDir."/a/a/lib".PATH_SEPARATOR.$oldIncludePath,
            get_include_path()
        );

        set_include_path($oldIncludePath);
    }

    public function testIncludePathFileWithoutPathsIsSkipped()
    {
        $package = new Package('a', '1.0', '1.0');
        $packages = array();

        $a = new Package("a/a", "1.0", "1.0");
        $packages[] = $a;

        $this->repository->expects($this->once())
            ->method("getCanonicalPackages")
            ->will($this->returnValue($packages));

        mkdir($this->vendorDir."/composer", 0777, true);

        $this->generator->dump($this->config, $this->repository, $package, $this->im, "composer", false, '_12');

        $this->assertFalse(file_exists($this->vendorDir."/composer/include_paths.php"));
    }

    public function testPreAndPostEventsAreDispatchedDuringAutoloadDump()
    {
        $this->eventDispatcher
            ->expects($this->at(0))
            ->method('dispatchScript')
            ->with(ScriptEvents::PRE_AUTOLOAD_DUMP, false);

        $this->eventDispatcher
            ->expects($this->at(1))
            ->method('dispatchScript')
            ->with(ScriptEvents::POST_AUTOLOAD_DUMP, false);

        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array('psr-0' => array('foo/bar/non/existing/')));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_8');
    }

    public function testUseGlobalIncludePath()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Main\\Foo' => '', 'Main\\Bar' => ''),
        ));
        $package->setTargetDir('Main/Foo/');

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->config->expects($this->at(2))
            ->method('get')
            ->with($this->equalTo('use-include-path'))
            ->will($this->returnValue(true));

        $this->fs->ensureDirectoryExists($this->vendorDir.'/a');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', false, 'IncludePath');
        $this->assertFileEquals(__DIR__.'/Fixtures/autoload_real_include_path.php', $this->vendorDir.'/composer/autoload_real.php');
    }

    public function testVendorDirExcludedFromWorkingDir()
    {
        $workingDir = $this->vendorDir.'/working-dir';
        $vendorDir = $workingDir.'/../vendor';

        $this->fs->ensureDirectoryExists($workingDir);
        chdir($workingDir);

        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Foo' => 'src'),
            'classmap' => array('classmap'),
            'files' => array('test.php'),
        ));

        $vendorPackage = new Package('b/b', '1.0', '1.0');
        $vendorPackage->setAutoload(array(
            'psr-0' => array('Bar' => 'lib'),
            'classmap' => array('classmaps'),
            'files' => array('bootstrap.php'),
        ));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array($vendorPackage)));

        $im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function ($package) use ($vendorDir) {
                $targetDir = $package->getTargetDir();

                return $vendorDir.'/'.$package->getName() . ($targetDir ? '/'.$targetDir : '');
            }));

        $this->fs->ensureDirectoryExists($workingDir.'/src/Foo');
        $this->fs->ensureDirectoryExists($workingDir.'/classmap');
        $this->fs->ensureDirectoryExists($vendorDir.'/composer');
        $this->fs->ensureDirectoryExists($vendorDir.'/b/b/lib/Bar');
        $this->fs->ensureDirectoryExists($vendorDir.'/b/b/classmaps');
        file_put_contents($workingDir.'/src/Foo/Bar.php', '<?php namespace Foo; class Bar {}');
        file_put_contents($workingDir.'/classmap/classes.php', '<?php namespace Foo; class Foo {}');
        file_put_contents($workingDir.'/test.php', '<?php class Foo {}');
        file_put_contents($vendorDir.'/b/b/lib/Bar/Foo.php', '<?php namespace Bar; class Foo {}');
        file_put_contents($vendorDir.'/b/b/classmaps/classes.php', '<?php namespace Bar; class Bar {}');
        file_put_contents($vendorDir.'/b/b/bootstrap.php', '<?php class Bar {}');

        $oldVendorDir = $this->vendorDir;
        $this->vendorDir = $vendorDir;
        $this->generator->dump($this->config, $this->repository, $package, $im, 'composer', true, '_13');
        $this->vendorDir = $oldVendorDir;

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Foo' => array($baseDir . '/src'),
    'Bar' => array($vendorDir . '/b/b/lib'),
);

EOF;

        $expectedClassmap = <<<'EOF'
<?php

// autoload_classmap.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Bar\\Bar' => $vendorDir . '/b/b/classmaps/classes.php',
    'Bar\\Foo' => $vendorDir . '/b/b/lib/Bar/Foo.php',
    'Foo\\Bar' => $baseDir . '/src/Foo/Bar.php',
    'Foo\\Foo' => $baseDir . '/classmap/classes.php',
);

EOF;

        $this->assertEquals($expectedNamespace, file_get_contents($vendorDir.'/composer/autoload_namespaces.php'));
        $this->assertEquals($expectedClassmap, file_get_contents($vendorDir.'/composer/autoload_classmap.php'));
        $this->assertContains("\n    \$vendorDir . '/b/b/bootstrap.php',\n", file_get_contents($vendorDir.'/composer/autoload_files.php'));
        $this->assertContains("\n    \$baseDir . '/test.php',\n", file_get_contents($vendorDir.'/composer/autoload_files.php'));
    }

    public function testUpLevelRelativePaths()
    {
        $workingDir = $this->workingDir.'/working-dir';
        mkdir($workingDir, 0777, true);
        chdir($workingDir);

        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Foo' => '../path/../src'),
            'classmap' => array('../classmap'),
            'files' => array('../test.php'),
        ));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->fs->ensureDirectoryExists($this->workingDir.'/src/Foo');
        $this->fs->ensureDirectoryExists($this->workingDir.'/classmap');
        file_put_contents($this->workingDir.'/src/Foo/Bar.php', '<?php namespace Foo; class Bar {}');
        file_put_contents($this->workingDir.'/classmap/classes.php', '<?php namespace Foo; class Foo {}');
        file_put_contents($this->workingDir.'/test.php', '<?php class Foo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_14');

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Foo' => array($baseDir . '/../src'),
);

EOF;

    $expectedClassmap = <<<'EOF'
<?php

// autoload_classmap.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir).'/working-dir';

return array(
    'Foo\\Bar' => $baseDir . '/../src/Foo/Bar.php',
    'Foo\\Foo' => $baseDir . '/../classmap/classes.php',
);

EOF;

        $this->assertEquals($expectedNamespace, file_get_contents($this->vendorDir.'/composer/autoload_namespaces.php'));
        $this->assertEquals($expectedClassmap, file_get_contents($this->vendorDir.'/composer/autoload_classmap.php'));
        $this->assertContains("\n    \$baseDir . '/../test.php',\n", file_get_contents($this->vendorDir.'/composer/autoload_files.php'));
    }

    public function testEmptyPaths()
    {
        $package = new Package('a', '1.0', '1.0');
        $package->setAutoload(array(
            'psr-0' => array('Foo' => ''),
            'classmap' => array(''),
        ));

        $this->repository->expects($this->once())
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $this->fs->ensureDirectoryExists($this->workingDir.'/Foo');
        file_put_contents($this->workingDir.'/Foo/Bar.php', '<?php namespace Foo; class Bar {}');
        file_put_contents($this->workingDir.'/class.php', '<?php namespace Classmap; class Foo {}');

        $this->generator->dump($this->config, $this->repository, $package, $this->im, 'composer', true, '_15');

        $expectedNamespace = <<<'EOF'
<?php

// autoload_namespaces.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir);

return array(
    'Foo' => array($baseDir . '/'),
);

EOF;

    $expectedClassmap = <<<'EOF'
<?php

// autoload_classmap.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir);

return array(
    'Classmap\\Foo' => $baseDir . '/class.php',
    'Foo\\Bar' => $baseDir . '/Foo/Bar.php',
);

EOF;

        $this->assertEquals($expectedNamespace, file_get_contents($this->vendorDir.'/composer/autoload_namespaces.php'));
        $this->assertEquals($expectedClassmap, file_get_contents($this->vendorDir.'/composer/autoload_classmap.php'));
    }

    private function assertAutoloadFiles($name, $dir, $type = 'namespaces')
    {
        $a = __DIR__.'/Fixtures/autoload_'.$name.'.php';
        $b = $dir.'/autoload_'.$type.'.php';
        $this->assertFileEquals($a, $b);
    }
}
