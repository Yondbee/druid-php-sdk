<?php
namespace Genetsis\UnitTest\Core\Http\Services;

use Codeception\Specify;
use Codeception\Test\Unit;
use Genetsis\core\OAuth\Collections\AuthMethods;

/**
 * @package Genetsis
 * @category UnitTest
 */
class AuthMethodsCollectionTest extends Unit
{

    use Specify;

    protected function _before()
    {
    }

    protected function _after()
    {
    }

    public function testAuthMethodsCollectionVerification()
    {
        $this->specify('Checks all collections values.', function(){
            $this->assertTrue(AuthMethods::check(AuthMethods::GRANT_TYPE_AUTH_CODE));
            $this->assertTrue(AuthMethods::check(AuthMethods::GRANT_TYPE_REFRESH_TOKEN));
            $this->assertTrue(AuthMethods::check(AuthMethods::GRANT_TYPE_CLIENT_CREDENTIALS));
            $this->assertTrue(AuthMethods::check(AuthMethods::GRANT_TYPE_VALIDATE_BEARER));
            $this->assertTrue(AuthMethods::check(AuthMethods::GRANT_TYPE_EXCHANGE_SESSION));
            $this->assertFalse(AuthMethods::check('nope'));
        });
    }

}
