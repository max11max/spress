<?php

/*
 * This file is part of the Yosymfony\Spress.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Yosymfony\Spress\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Yosymfony\Spress\Console\Application;
use Yosymfony\Spress\Command\NewPostCommand;

class NewPostCommandTest extends TestCase
{
    protected $app;
    protected $tmpDir;
    protected $currentDir;
    protected $fs;

    public function setUp()
    {
        $autoloaders = spl_autoload_functions();

        $this->app = new Application($autoloaders[0][0]);

        $this->tmpDir = sys_get_temp_dir().'/spress-tests';

        $this->fs = new Filesystem();
        $this->fs->mirror(__DIR__.'/../../src/Core/Tests/fixtures/project', $this->tmpDir);

        $this->currentDir = getcwd();

        chdir($this->tmpDir);
    }

    public function tearDown()
    {
        chdir($this->currentDir);

        $this->fs->remove($this->tmpDir);
    }

    public function testNewPost()
    {
        $this->app->add(new NewPostCommand());

        $command = $this->app->find('new:post');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--title' => 'My second post',
            '--layout' => 'post',
            '--date' => '2015-01-01',
            '--tags' => 'tag1, tag2',
            '--categories' => 'category1, category2',
        ], ['interactive' => false]);

        $output = $commandTester->getDisplay();

        $this->assertRegExp('/2015-01-01-my-second-post.md/', $output);

        $fileContent = file_get_contents($this->tmpDir.'/src/content/posts/2015-01-01-my-second-post.md');

        $this->assertRegExp('/title: "My second post"/', $fileContent);
        $this->assertRegExp('/layout: post/', $fileContent);
        $this->assertRegExp('/tags: \[tag1,tag2\]/', $fileContent);
        $this->assertRegExp('/categories: \[category1,category2\]/', $fileContent);
    }
}
