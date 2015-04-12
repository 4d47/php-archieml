<?php

class ArchieMLTest extends \PHPUnit_Framework_TestCase
{
    public function testParsingValues()
    {
        $this->assertSame('value', ArchieML::load("key:value")['key'], 'parses key value pairs');
        $this->assertSame('value', ArchieML::load("key:value")['key'], 'parses key value pairs');
        $this->assertSame('value', ArchieML::load("  key  :value")['key'], 'ignores spaces on either side of the key');
        $this->assertSame('value', ArchieML::load("\t\tkey\t\t:value")['key'], 'ignores tabs on either side of the key');
        $this->assertSame('value', ArchieML::load("key:  value  ")['key'], 'ignores spaces on either side of the value');
        $this->assertSame('value', ArchieML::load("key:\t\tvalue\t\t")['key'], 'ignores tabs on either side of the value');
        $this->assertSame('newvalue', ArchieML::load("key:value\nkey:newvalue")['key'], 'dupliate keys are assigned the last given value');
        $this->assertSame(':value', ArchieML::load("key::value")['key'], 'allows non-letter characters at the start of values');

        $this->assertSame(['key', 'Key'], array_keys(ArchieML::load("key:value\nKey:Value")), 'keys are case sensitive');
        $this->assertSame('value', ArchieML::load("other stuff\nkey:value\nother stuff")['key'], "non-keys don't affect parsing");
    }

    public function testValidKeys()
    {
        $this->assertSame(ArchieML::load("a-_1:value")['a-_1'], 'value', 'letters, numbers, dashes and underscores are valid key components');
        $this->assertSame(0, count(ArchieML::load("k ey:value")), 'spaces are not allowed in keys');
        $this->assertSame(0, count(ArchieML::load("k&ey:value")), 'symbols are not allowed in keys');
        $this->assertSame('value', ArchieML::load("scope.key:value")['scope']['key'], 'keys can be nested using dot-notation');
        $this->assertSame('value', ArchieML::load("scope.key:value\nscope.otherkey:value")['scope']['key'], "earlier keys within scopes aren't deleted when using dot-notation");
        $this->assertSame('value', ArchieML::load("scope.level:value\nscope.level.level:value")['scope']['level']['level'], 'the value of key that used to be a string object should be replaced with an object if necessary');
        $this->assertSame('value', ArchieML::load("scope.level.level:value\nscope.level:value")['scope']['level'], 'the value of key that used to be a parent object should be replaced with a string if necessary');
    }

    public function testValidValues()
    {
        $this->assertSame('<strong>value</strong>', ArchieML::load("key:<strong>value</strong>")['key'], 'HTML is allowed');
    }

    public function testSkip()
    {
        $this->assertSame(0, count(ArchieML::load("  :skip  \nkey:value\n:endskip")), 'ignores spaces on either side of :skip');
        $this->assertSame(0, count(ArchieML::load("\t\t:skip\t\t\nkey:value\n:endskip")), 'ignores tabs on either side of :skip');
        $this->assertSame(0, count(ArchieML::load(":skip\nkey:value\n  :endskip  ")), 'ignores spaces on either side of :endskip');
        $this->assertSame(0, count(ArchieML::load(":skip\nkey:value\n\t\t:endskip\t\t")), 'ignores tabs on either side of :endskip');

        $this->assertSame(1, count(ArchieML::load(":skip\n:endskip\nkey:value")), 'starts parsing again after :endskip');
        $this->assertSame(0, count(ArchieML::load(":sKiP\nkey:value\n:eNdSkIp")), ':skip and :endskip are case insensitive');

        $this->assertSame(0, count(ArchieML::load(":skipthis\nkey:value\n:endskip")), "parse :skip as a special command even if more is appended to word");
        $this->assertSame(0, count(ArchieML::load(":skip this text  \nkey:value\n:endskip")), 'ignores all content on line after :skip + space');
        $this->assertSame(0, count(ArchieML::load(":skip\tthis text\t\t\nkey:value\n:endskip")), 'ignores all content on line after :skip + tab');

        $this->assertSame(1, count(ArchieML::load(":skip\n:endskiptheabove\nkey:value")), "parse :endskip as a special command even if more is appended to word");
        $this->assertSame(1, count(ArchieML::load(":skip\n:endskip the above\nkey:value")), 'ignores all content on line after :endskip + space');
        $this->assertSame(1, count(ArchieML::load(":skip\n:endskip\tthe above\nkey:value")), 'ignores all content on line after :endskip + tab');
        $this->assertSame(0, count(ArchieML::load(":skip\n:end\tthe above\nkey:value")), 'does not parse :end as an :endskip');

        $this->assertSame(['key1', 'key2'], array_keys(ArchieML::load("key1:value1\n:skip\nother:value\n\n:endskip\n\nkey2:value2")), 'ignores keys within a skip block');
    }

    public function testIgnore()
    {
        $this->assertSame('value', ArchieML::load("key:value\n:ignore")['key'], "text before ':ignore' should be included");
        $this->assertFalse(ArchieML::load(":ignore\nkey:value")->offsetExists('key'), "text after ':ignore' should be ignored");
        $this->assertFalse(ArchieML::load(":iGnOrE\nkey:value")->offsetExists('key'), "':ignore' is case insensitive");
        $this->assertFalse(ArchieML::load("  :ignore  \nkey:value")->offsetExists('key'), "ignores spaces on either side of :ignore");
        $this->assertFalse(ArchieML::load("\t\t:ignore\t\t\nkey:value")->offsetExists('key'), "ignores tabs on either side of :ignore");
        $this->assertFalse(ArchieML::load(":ignorethis\nkey:value")->offsetExists('key'), "parses :ignore as a special command even if more is appended to word");
        $this->assertFalse(ArchieML::load(":ignore the below\nkey:value")->offsetExists('key'), "ignores all content on line after :ignore + space");
        $this->assertFalse(ArchieML::load(":ignore\tthe below\nkey:value")->offsetExists('key'), "ignores all content on line after :ignore + tab");
    }

    public function testMultiLineValues()
    {
        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n:end")['key'], 'adds additional lines to value if followed by an \':end\'');
        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n:EnD")['key'], '\':end\' is case insensitive');
        $this->assertSame("value\n\n\t \nextra", ArchieML::load("key:value\n\n\t \nextra\n:end")['key'], 'preserves blank lines and whitespace lines in the middle of content');
        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\t \n:end")['key'], "doesn't preserve whitespace at the end of the key");
        $this->assertSame("value\t \nextra", ArchieML::load("key:value\t \nextra\n:end")['key'], 'preserves whitespace at the end of the original line');

        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n \n\t\n:end")['key'], 'ignores whitespace and newlines before the \':end\'');
        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n  :end  ")['key'], 'ignores spaces on either side of :end');
        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n\t\t:end\t\t")['key'], 'ignores tabs on either side of :end');

        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n:endthis")['key'], "parses :end as a special command even if more is appended to word");
        $this->assertSame("value", ArchieML::load("key:value\nextra\n:endskip")['key'], "does not parse :endskip as an :end");
        $this->assertSame("value\n:notacommand", ArchieML::load("key:value\n:notacommand\n:end")['key'], "ordinary text that starts with a colon is included");
        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n:end this")['key'], "ignores all content on line after :end + space");
        $this->assertSame("value\nextra", ArchieML::load("key:value\nextra\n:end\tthis")['key'], "ignores all content on line after :end + tab");

        $this->assertSame(":value", ArchieML::load("key::value\n:end")['key'], "doesn't escape colons on first line");
        $this->assertSame("\\:value", ArchieML::load("key:\\:value\n:end")['key'], "doesn't escape colons on first line");
        $this->assertSame("value\nkey2\\:value", ArchieML::load("key:value\nkey2\\:value\n:end")['key'], 'does not allow escaping keys');
        $this->assertSame("value\nkey2:value", ArchieML::load("key:value\n\\key2:value\n:end")['key'], 'allows escaping key lines with a leading backslash');
        $this->assertSame("value\n:end", ArchieML::load("key:value\n\\:end\n:end")['key'], 'allows escaping commands at the beginning of lines');
        $this->assertSame("value\n:endthis", ArchieML::load("key:value\n\\:endthis\n:end")['key'], 'allows escaping commands with extra text at the beginning of lines');
        $this->assertSame("value\n:notacommand", ArchieML::load("key:value\n\\:notacommand\n:end")['key'], 'allows escaping of non-commands at the beginning of lines');

        $this->assertSame("value\n* value", ArchieML::load("key:value\n* value\n:end")['key'], 'allows simple array style lines');
        $this->assertSame("value\n* value", ArchieML::load("key:value\n\\* value\n:end")['key'], 'escapes "*" within multi-line values when not in a simple array');

        $this->assertSame("value\n{scope}", ArchieML::load("key:value\n\\{scope}\n:end")['key'], 'allows escaping {scopes} at the beginning of lines');
        $this->assertSame("value", ArchieML::load("key:value\n\\[comment]\n:end")['key'], 'allows escaping [comments] at the beginning of lines');
        $this->assertSame("value\n[array]", ArchieML::load("key:value\n\\[[array]]\n:end")['key'], 'allows escaping [[arrays]] at the beginning of lines');

        $this->assertSame("value", ArchieML::load("key:value\ntext\n[array]\nmore text\n:end")['key'], 'arrays within a multi-line value breaks up the value');
        $this->assertSame("value", ArchieML::load("key:value\ntext\n{scope}\nmore text\n:end")['key'], 'objects within a multi-line value breaks up the value');
        $this->assertSame("value\ntext\n* value\nmore text", ArchieML::load("key:value\ntext\n* value\nmore text\n:end")['key'], 'bullets within a multi-line value do not break up the value');
        $this->assertSame("value\ntext\nmore text", ArchieML::load("key:value\ntext\n:skip\n:endskip\nmore text\n:end")['key'], 'skips within a multi-line value do not break up the value');

        $this->assertSame("value\n\\:end", ArchieML::load("key:value\n\\\\:end\n:end")['key'], 'allows escaping initial backslash at the beginning of lines');
        $this->assertSame("value\n\\\\:end", ArchieML::load("key:value\n\\\\\\:end\n:end")['key'], 'escapes only one initial backslash');

        $this->assertSame("value\n:end\n:ignore\n:endskip\n:skip", ArchieML::load("key:value\n\\:end\n\\:ignore\n\\:endskip\n\\:skip\n:end")['key'], 'allows escaping multiple lines in a value');

        $this->assertSame("value\nLorem key2\\:value", ArchieML::load("key:value\nLorem key2\\:value\n:end")['key'], "doesn't escape colons after beginning of lines");
    }

    public function testScopes()
    {
        $this->assertInternalType('array', ArchieML::load("{scope}")['scope'], '{scope} creates an empty object at "scope"');
        $this->assertTrue(array_key_exists('scope', ArchieML::load("  {scope}  ")), 'ignores spaces on either side of {scope}');
        $this->assertTrue(array_key_exists('scope', ArchieML::load("\t\t{scope}\t\t")), 'ignores tabs on either side of {scope}');
        $this->assertTrue(array_key_exists('scope', ArchieML::load("{  scope  }")), 'ignores spaces on either side of {scope} variable name');
        $this->assertTrue(array_key_exists('scope', ArchieML::load("{\t\tscope\t\t}")), 'ignores tabs on either side of {scope} variable name');
        $this->assertTrue(array_key_exists('scope', ArchieML::load("{scope}a")), 'ignores text after {scope}');

        $this->assertSame('value', ArchieML::load("key:value\n{scope}")['key'], 'items before a {scope} are not namespaced');
        $this->assertSame('value', ArchieML::load("{scope}\nkey:value")['scope']['key'], 'items after a {scope} are namespaced');
        $this->assertSame('value', ArchieML::load("{scope.scope}\nkey:value")['scope']['scope']['key'], 'scopes can be nested using dot-notaion');
        $this->assertSame(2, count(ArchieML::load("{scope}\nkey:value\n{}\n{scope}\nother:value")['scope']), 'scopes can be reopened');
        $this->assertSame('value', ArchieML::load("{scope.scope}\nkey:value\n{scope.otherscope}key:value")['scope']['scope']['key'], 'scopes do not overwrite existing values');

        $this->assertSame('value', ArchieML::load("{scope}\n{}\nkey:value")['key'], '{} resets to the global scope');
        $this->assertSame('value', ArchieML::load("{scope}\n{  }\nkey:value")['key'], 'ignore spaces inside {}');
        $this->assertSame('value', ArchieML::load("{scope}\n{\t\t}\nkey:value")['key'], 'ignore tabs inside {}');
        $this->assertSame('value', ArchieML::load("{scope}\n  {}  \nkey:value")['key'], 'ignore spaces on either side of {}');
        $this->assertSame('value', ArchieML::load("{scope}\n\t\t{}\t\t\nkey:value")['key'], 'ignore tabs on either side of {}');
    }

    public function testArrays()
    {
        $this->assertSame(0, count(ArchieML::load("[array]")['array']), '[array] creates an empty array at "array"');
        $this->assertTrue(array_key_exists('array', ArchieML::load("  [array]  ")), 'ignores spaces on either side of [array]');
        $this->assertTrue(array_key_exists('array', ArchieML::load("\t\t[array]\t\t")), 'ignores tabs on either side of [array]');
        $this->assertTrue(array_key_exists('array', ArchieML::load("[  array  ]")), 'ignores spaces on either side of [array] variable name');
        $this->assertTrue(array_key_exists('array', ArchieML::load("[\t\tarray\t\t]")), 'ignores tabs on either side of [array] variable name');
        $this->assertTrue(array_key_exists('array', ArchieML::load("[array]a")), 'ignores text after [array]');

        $this->assertSame(0, count(ArchieML::load("[scope.array]")['scope']['array']), 'arrays can be nested using dot-notaion');

        $this->assertSame([['scope' => ['key' => 'value']], ['scope' => ['key' => 'value']]], ArchieML::load("[array]\nscope.key: value\nscope.key: value")['array'], 'array values can be nested using dot-notaion');

        $this->assertSame('value', ArchieML::load("[array]\n[]\nkey:value")['key'], '[] resets to the global scope');
        $this->assertSame('value', ArchieML::load("[array]\n[  ]\nkey:value")['key'], 'ignore spaces inside []');
        $this->assertSame('value', ArchieML::load("[array]\n[\t\t]\nkey:value")['key'], 'ignore tabs inside []');
        $this->assertSame('value', ArchieML::load("[array]\n  []  \nkey:value")['key'], 'ignore spaces on either side of []');
        $this->assertSame('value', ArchieML::load("[array]\n\t\t[]\t\t\nkey:value")['key'], 'ignore tabs on either side of []');
    }

    public function testSimpleArrays()
    {
        $this->assertSame('Value', ArchieML::load("[array]\n*Value")['array'][0], 'creates a simple array when an \'*\' is encountered first');
        $this->assertSame('Value', ArchieML::load("[array]\n  *  Value")['array'][0], 'ignores spaces on either side of \'*\'');
        $this->assertSame('Value', ArchieML::load("[array]\n\t\t*\t\tValue")['array'][0], 'ignores tabs on either side of \'*\'');
        $this->assertSame(2, count(ArchieML::load("[array]\n*Value1\n*Value2")['array']), 'adds multiple elements');
        $this->assertSame(["Value1", "Value2"], ArchieML::load("[array]\n*Value1\nNon-element\n*Value2")['array'], 'ignores all other text between elements');
        $this->assertSame(["Value1", "Value2"], ArchieML::load("[array]\n*Value1\nkey:value\n*Value2")['array'], 'ignores key:value pairs between elements');
        $this->assertSame("value", ArchieML::load("[array]\n*Value1\n[]\nkey:value")['key'], 'parses key:values normally after an end-array');

        $this->assertSame("Value1\nextra", ArchieML::load("[array]\n*Value1\nextra\n:end")['array'][0], 'multi-line values are allowed');
        $this->assertSame("Value1\n* extra", ArchieML::load("[array]\n*Value1\n\\* extra\n:end")['array'][0], 'allows escaping of "*" within multi-line values in simple arrays');
        $this->assertSame("Value1\n:end", ArchieML::load("[array]\n*Value1\n\\:end\n:end")['array'][0], 'allows escaping of command keys within multi-line values');
        $this->assertSame("Value1\nkey\\:value", ArchieML::load("[array]\n*Value1\nkey\\:value\n:end")['array'][0], 'does not allow escaping of keys within multi-line values');
        $this->assertSame("Value1\nkey:value", ArchieML::load("[array]\n*Value1\n\\key:value\n:end")['array'][0], 'allows escaping key lines with a leading backslash');
        $this->assertSame("Value1\nword key\\:value", ArchieML::load("[array]\n*Value1\nword key\\:value\n:end")['array'][0], 'does not allow escaping of colons not at the beginning of lines');

        $this->assertSame('value', ArchieML::load("[array]\n* value\n[array]\nmore text\n:end")['array'][0], 'arrays within a multi-line value breaks up the value');
        $this->assertSame('value', ArchieML::load("[array]\n* value\n{scope}\nmore text\n:end")['array'][0], 'objects within a multi-line value breaks up the value');
        $this->assertSame("value\nkey: value\nmore text", ArchieML::load("[array]\n* value\nkey: value\nmore text\n:end")['array'][0], 'key/values within a multi-line value do not break up the value');
        $this->assertSame('value', ArchieML::load("[array]\n* value\n* value\nmore text\n:end")['array'][0], 'bullets within a multi-line value break up the value');
        $this->assertSame("value\nmore text", ArchieML::load("[array]\n* value\n:skip\n:endskip\nmore text\n:end")['array'][0], 'skips within a multi-line value do not break up the value');

        $this->assertSame(2, count(ArchieML::load("[array]\n*Value\n[]\n[array]\n*Value")['array']), 'arrays that are reopened add to existing array');
        $this->assertSame(["Value"], ArchieML::load("[array]\n*Value\n[]\n[array]\nkey:value")['array'], 'simple arrays that are reopened remain simple');

        $this->assertSame('simple value', ArchieML::load("a.b:complex value\n[a.b]\n*simple value")['a']['b'][0], 'simple ararys overwrite existing keys');
    }

    public function testComplexArrays()
    {
        $this->assertSame('value', ArchieML::load("[array]\nkey:value")['array'][0]['key'], 'keys after an [array] are included as items in the array');
        $this->assertSame('value', ArchieML::load("[array]\nkey:value\nsecond:value")['array'][0]['second'], 'array items can have multiple keys');
        $this->assertSame(2, count(ArchieML::load("[array]\nkey:value\nsecond:value\nkey:value")['array']), 'when a duplicate key is encountered, a new item in the array is started');
        $this->assertSame('second', ArchieML::load("[array]\nkey:first\nkey:second")['array'][1]['key'], 'when a duplicate key is encountered, a new item in the array is started');
        $this->assertSame('second', ArchieML::load("[array]\nscope.key:first\nscope.key:second")['array'][1]['scope']['key'], 'when a duplicate key is encountered, a new item in the array is started');

        $this->assertSame(1, count(ArchieML::load("[array]\nkey:value\nscope.key:value")['array']), 'duplicate keys must match on dot-notation scope');
        $this->assertSame(1, count(ArchieML::load("[array]\nscope.key:value\nkey:value\notherscope.key:value")['array']), 'duplicate keys must match on dot-notation scope');

        $this->assertSame('value', ArchieML::load("[array]\nkey:value\n[array]\nmore text\n:end")['array'][0]['key'], 'arrays within a multi-line value breaks up the value');
        $this->assertSame('value', ArchieML::load("[array]\nkey:value\n{scope}\nmore text\n:end")['array'][0]['key'], 'objects within a multi-line value breaks up the value');
        $this->assertSame('value', ArchieML::load("[array]\nkey:value\nother: value\nmore text\n:end")['array'][0]['key'], 'key/values within a multi-line value break up the value');
        $this->assertSame("value\n* value\nmore text", ArchieML::load("[array]\nkey:value\n* value\nmore text\n:end")['array'][0]['key'], 'bullets within a multi-line value do not break up the value');
        $this->assertSame("value\nmore text", ArchieML::load("[array]\nkey:value\n:skip\n:endskip\nmore text\n:end")['array'][0]['key'], 'skips within a multi-line value do not break up the value');

        $this->assertSame(2, count(ArchieML::load("[array]\nkey:value\n[]\n[array]\nkey:value")['array']), 'arrays that are reopened add to existing array');
        $this->assertSame(["key" => "value"], ArchieML::load("[array]\nkey:value\n[]\n[array]\n*Value")['array'][0], 'complex arrays that are reopened remain complex');

        $this->assertSame('value', ArchieML::load("a.b:complex value\n[a.b]\nkey:value")['a']['b'][0]['key'], 'complex arrays overwrite existing keys');
    }

    public function testInlineComments()
    {
        $this->assertSame('value  value', ArchieML::load("key:value [inline comments] value")['key'], 'ignore comments inside of [single brackets]');
        $this->assertSame('value  value  value', ArchieML::load("key:value [inline comments] value [inline comments] value")['key'], 'supports multiple inline comments on a single line');
        $this->assertSame('value   value', ArchieML::load("key:value [inline comments] [inline comments] value")['key'], 'supports adjacent comments');
        $this->assertSame('value  value', ArchieML::load("key:value [inline comments][inline comments] value")['key'], 'supports no-space adjacent comments');
        $this->assertSame('value', ArchieML::load("key:[inline comments] value")['key'], 'supports comments at beginning of string');
        $this->assertSame('value', ArchieML::load("key:value [inline comments]")['key'], 'supports comments at end of string');
        $this->assertSame('value  value', ArchieML::load("key:value [inline comments] value [inline comments]")['key'], 'whitespace before a comment that appears at end of line is ignored');

        $this->assertSame('value ][ value', ArchieML::load("key:value ][ value")['key'], 'unmatched single brackets are preserved');
        $this->assertSame("value  on\nmultiline", ArchieML::load("key:value [inline comments] on\nmultiline\n:end")['key'], 'inline comments are supported on the first of multi-line values');
        $this->assertSame("value\nmultiline", ArchieML::load("key:value\nmultiline [inline comments]\n:end")['key'], 'inline comments are supported on subsequent lines of multi-line values');

        $this->assertSame("value  \n multiline", ArchieML::load("key: [] value [] \n multiline [] \n:end")['key'], 'whitespace around comments is preserved, except at the beinning and end of a value');

        $this->assertSame("value [inline\ncomments] value", ArchieML::load("key:value [inline\ncomments] value\n:end")['key'], 'inline comments cannot span multiple lines');
        $this->assertSame("value \n[inline\ncomments] value", ArchieML::load("key:value \n[inline\ncomments] value\n:end")['key'], 'inline comments cannot span multiple lines');

        $this->assertSame("value [brackets] value", ArchieML::load("key:value [[brackets]] value")['key'], 'text inside [[double brackets]] is included as [single brackets]');
        $this->assertSame("value ]][[ value", ArchieML::load("key:value ]][[ value")['key'], 'unmatched double brackets are preserved');

        $this->assertSame('Value', ArchieML::load("[array]\n*Val[comment]ue")['array'][0], 'comments work in simple arrays');
        $this->assertSame('Val[real]ue', ArchieML::load("[array]\n*Val[[real]]ue")['array'][0], 'double brackets work in simple arrays');
    }
}
