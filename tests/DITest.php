<?php
use PHPUnit\Framework\TestCase;
use DElfimov\DI\Container;

/**
 * @covers DElfimov\DI\Container
 */

class DITest extends TestCase
{
    /**
     * @dataProvider containerProvider
     */
    public function testContainer($container)
    {
        $this->assertEquals(true, $container instanceof Container);
    }

    /**
     * @dataProvider containerProvider
     */
    public function testRules($container)
    {
        $dt = $container->get('dt');
        $dt2 = $container->get('dt2');
        $tz = $container->get('tz');
        $this->assertEquals(true, $dt instanceof \DateTime);
        $this->assertEquals('Pacific/Nauru', $dt->getTimezone()->getName());
        $this->assertEquals(true, $dt2 instanceof \DateTime);
        $this->assertEquals('Europe/London', $dt2->getTimezone()->getName());
        $this->assertEquals(true, $tz instanceof \DateTimeZone);
        $this->assertEquals('Europe/London', $tz->getName());
    }


    public function coreProvider()
    {
        $return = [];
        foreach ($this->URIStore as $store) {
            $uri = $store[1];
            $template = $store[0];
            $request = empty($store[2]) ? [] : $store[2];
            $get = empty($store[3]) ? [] : $store[3];
            $core = new Core($uri, realpath(__DIR__ . '/..'));
            $return[] = [
                $core, $template, $request, $get
            ];
        }
        return $return;
    }

    public function containerProvider()
    {
        $return = [];
        $return[] = [new Container(include __DIR__ . '/config.php')];
        return $return;
    }
}
