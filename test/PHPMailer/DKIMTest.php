<?php

/**
 * PHPMailer - PHP email transport unit tests.
 * PHP version 5.5.
 *
 * @author    Marcus Bointon <phpmailer@synchromedia.co.uk>
 * @author    Andy Prevost
 * @copyright 2012 - 2020 Marcus Bointon
 * @copyright 2004 - 2009 Andy Prevost
 * @license   http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace PHPMailer\Test\PHPMailer;

use PHPMailer\Test\TestCase;

/**
 * Test DKIM signing functionality.
 */
final class DKIMTest extends TestCase
{

    /**
     * DKIM body canonicalization tests.
     *
     * @see https://tools.ietf.org/html/rfc6376#section-3.4.4
     */
    public function testDKIMBodyCanonicalization()
    {
        //Example from https://tools.ietf.org/html/rfc6376#section-3.4.5
        $prebody = " C \r\nD \t E\r\n\r\n\r\n";
        $postbody = " C \r\nD \t E\r\n";
        self::assertSame("\r\n", $this->Mail->DKIM_BodyC(''), 'DKIM empty body canonicalization incorrect');
        self::assertSame(
            'frcCV1k9oG9oKj3dpUqdJg1PxRT2RSN/XKdLCPjaYaY=',
            base64_encode(hash('sha256', $this->Mail->DKIM_BodyC(''), true)),
            'DKIM canonicalized empty body hash mismatch'
        );
        self::assertSame($postbody, $this->Mail->DKIM_BodyC($prebody), 'DKIM body canonicalization incorrect');
    }

    /**
     * DKIM header canonicalization tests.
     *
     * @see https://tools.ietf.org/html/rfc6376#section-3.4.2
     */
    public function testDKIMHeaderCanonicalization()
    {
        //Example from https://tools.ietf.org/html/rfc6376#section-3.4.5
        $preheaders = "A: X\r\nB : Y\t\r\n\tZ  \r\n";
        $postheaders = "a:X\r\nb:Y Z\r\n";
        self::assertSame(
            $postheaders,
            $this->Mail->DKIM_HeaderC($preheaders),
            'DKIM header canonicalization incorrect'
        );
        //Check that long folded lines with runs of spaces are canonicalized properly
        $preheaders = 'Long-Header-1: <https://example.com/somescript.php?' .
            "id=1234567890&name=Abcdefghijklmnopquestuvwxyz&hash=\r\n abc1234\r\n" .
            "Long-Header-2: This  is  a  long  header  value  that  contains  runs  of  spaces and trailing    \r\n" .
            ' and   is   folded   onto   2   lines';
        $postheaders = 'long-header-1:<https://example.com/somescript.php?id=1234567890&' .
            "name=Abcdefghijklmnopquestuvwxyz&hash= abc1234\r\nlong-header-2:This is a long" .
            ' header value that contains runs of spaces and trailing and is folded onto 2 lines';
        self::assertSame(
            $postheaders,
            $this->Mail->DKIM_HeaderC($preheaders),
            'DKIM header canonicalization of long lines incorrect'
        );
    }

    /**
     * DKIM copied header fields tests.
     *
     * @group dkim
     *
     * @see https://tools.ietf.org/html/rfc6376#section-3.5
     */
    public function testDKIMOptionalHeaderFieldsCopy()
    {
        $privatekeyfile = 'dkim_private.pem';
        $pk = openssl_pkey_new(
            [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        openssl_pkey_export_to_file($pk, $privatekeyfile);
        $this->Mail->DKIM_private = 'dkim_private.pem';

        //Example from https://tools.ietf.org/html/rfc6376#section-3.5
        $from = 'from@example.com';
        $to = 'to@example.com';
        $date = 'date';
        $subject = 'example';

        $headerLines = "From:$from\r\nTo:$to\r\nDate:$date\r\n";
        $copyHeaderFields = " z=From:$from\r\n |To:$to\r\n |Date:$date\r\n |Subject:$subject;\r\n";

        $this->Mail->DKIM_copyHeaderFields = true;
        self::assertStringContainsString(
            $copyHeaderFields,
            $this->Mail->DKIM_Add($headerLines, $subject, ''),
            'DKIM header with copied header fields incorrect'
        );

        $this->Mail->DKIM_copyHeaderFields = false;
        self::assertStringNotContainsString(
            $copyHeaderFields,
            $this->Mail->DKIM_Add($headerLines, $subject, ''),
            'DKIM header without copied header fields incorrect'
        );

        unlink($privatekeyfile);
    }

    /**
     * DKIM signing extra headers tests.
     *
     * @group dkim
     */
    public function testDKIMExtraHeaders()
    {
        $privatekeyfile = 'dkim_private.pem';
        $pk = openssl_pkey_new(
            [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        openssl_pkey_export_to_file($pk, $privatekeyfile);
        $this->Mail->DKIM_private = 'dkim_private.pem';

        //Example from https://tools.ietf.org/html/rfc6376#section-3.5
        $from = 'from@example.com';
        $to = 'to@example.com';
        $date = 'date';
        $subject = 'example';
        $anyHeader = 'foo';
        $unsubscribeUrl = '<https://www.example.com/unsubscribe/?newsletterId=anytoken&amp;actionToken=anyToken' .
                            '&otherParam=otherValue&anotherParam=anotherVeryVeryVeryLongValue>';

        $this->Mail->addCustomHeader('X-AnyHeader', $anyHeader);
        $this->Mail->addCustomHeader('Baz', 'bar');
        $this->Mail->addCustomHeader('List-Unsubscribe', $unsubscribeUrl);

        $this->Mail->DKIM_extraHeaders = ['Baz', 'List-Unsubscribe'];

        $headerLines = "From:$from\r\nTo:$to\r\nDate:$date\r\n";
        $headerLines .= "X-AnyHeader:$anyHeader\r\nBaz:bar\r\n";
        $headerLines .= 'List-Unsubscribe:' . $this->Mail->encodeHeader($unsubscribeUrl) . "\r\n";

        $headerFields = 'h=From:To:Date:Baz:List-Unsubscribe:Subject';

        $result = $this->Mail->DKIM_Add($headerLines, $subject, '');

        self::assertStringContainsString($headerFields, $result, 'DKIM header with extra headers incorrect');

        unlink($privatekeyfile);
    }

    /**
     * DKIM Signing tests.
     *
     * @requires extension openssl
     */
    public function testDKIM()
    {
        $this->Mail->Subject .= ': DKIM signing';
        $this->Mail->Body = 'This message is DKIM signed.';
        $this->buildBody();
        $privatekeyfile = 'dkim_private.pem';
        //Make a new key pair
        //(2048 bits is the recommended minimum key length -
        //gmail won't accept less than 1024 bits)
        $pk = openssl_pkey_new(
            [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        openssl_pkey_export_to_file($pk, $privatekeyfile);
        $this->Mail->DKIM_domain = 'example.com';
        $this->Mail->DKIM_private = $privatekeyfile;
        $this->Mail->DKIM_selector = 'phpmailer';
        $this->Mail->DKIM_passphrase = ''; //key is not encrypted
        self::assertTrue($this->Mail->send(), 'DKIM signed mail failed');
        $this->Mail->isMail();
        self::assertTrue($this->Mail->send(), 'DKIM signed mail via mail() failed');
        unlink($privatekeyfile);
    }
}
