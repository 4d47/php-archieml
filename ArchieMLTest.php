<?php

class ArchieMLTest extends \PHPUnit_Framework_TestCase
{
    const TEST_FILES = 'test/archieml.org/test/1.0/*.aml';

    public function testBasic()
    {
        $this->assertSame('value', ArchieML::load('key:value
this is ignored')['key'], 'parses key value pairs');
    }

    /**
     * @dataProvider sharedProvider
     */
    public function testShared($expected, $actual, $message)
    {
        $this->assertEquals($expected, $actual, $message);
    }

    public function sharedProvider()
    {
        // $this->markTestSkipped();
        $tests = [];
        foreach (glob(self::TEST_FILES) as $filename) {
            # TODO: whats up with: metadata = load(..., { comments: false})
            $category = basename($filename, '.aml');
            if ($category == 'all.0')
                continue;
            $actual = ArchieML::load(file_get_contents($filename));
            $message = $actual['test'];
            $expected = json_decode($actual['result'], true);
            unset($actual['test'], $actual['result']);
            $tests[$category] = [ $expected, $actual, $message ];
        }
        return $tests;
    }
}
