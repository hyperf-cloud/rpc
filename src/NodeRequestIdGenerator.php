<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Rpc;

use Hyperf\Contract\IdGeneratorInterface;
use Hyperf\Utils\Base62;

class NodeRequestIdGenerator implements IdGeneratorInterface
{
    public function generate(): string
    {
        return $this->getNode() . Base62::encode(intval(microtime(true) * 1000));
    }

    /**
     * Retrieves the node mac address and request time from id.
     */
    public function decode(string $id): array
    {
        $len = strlen(Base62::encode(intval(microtime(true) * 1000)));
        $macStr = substr($id, 0, -$len);
        $microtime = Base62::decode(substr($id, -$len)) / 1000;
        $node = str_pad(sprintf('%x', Base62::decode($macStr)), 12, '0', STR_PAD_LEFT);
        return [
            'node' => trim(preg_replace('/(..)/', '\1:', $node), ':'),
            'time' => \DateTime::createFromFormat('U.u', (string) $microtime),
        ];
    }

    /**
     * Returns the system node ID.
     *
     * @return string System node ID as a hexadecimal string
     */
    public function getNode()
    {
        static $node = null;

        if ($node !== null) {
            return $node;
        }

        $str = $this->getMacAddress() ?: $this->getIfconfig() ?: $this->randomBytes();
        return Base62::encode(hexdec($str));
    }

    protected function randomBytes()
    {
        $node = hexdec(bin2hex(random_bytes(6)));

        // Set the multicast bit; see RFC 4122, section 4.5.
        $node = $node | 0x010000000000;

        return str_pad(dechex($node), 12, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the network interface configuration for the system.
     *
     * @codeCoverageIgnore
     * @return false|string
     */
    protected function getIfconfig()
    {
        if (strpos(strtolower(ini_get('disable_functions')), 'passthru') !== false) {
            return '';
        }

        ob_start();
        switch (strtoupper(substr(php_uname('a'), 0, 3))) {
            case 'WIN':
                passthru('ipconfig /all 2>&1');
                break;
            case 'DAR':
                passthru('ifconfig 2>&1');
                break;
            case 'FRE':
                passthru('netstat -i -f link 2>&1');
                break;
            case 'LIN':
            default:
                passthru('netstat -ie 2>&1');
                break;
        }

        $output = ob_get_clean();
        $pattern = '/[^:]([0-9A-Fa-f]{2}([:-])[0-9A-Fa-f]{2}(\2[0-9A-Fa-f]{2}){4})[^:]/';
        if (preg_match_all($pattern, $output, $matches)) {
            return str_replace([':', '-'], '', $matches[1][0]);
        }
        return false;
    }

    /**
     * Returns mac address from the first system interface via the sysfs interface.
     *
     * @return false|string
     */
    protected function getMacAddress()
    {
        if (strtoupper(php_uname('s')) !== 'LINUX') {
            return false;
        }
        foreach (glob('/sys/class/net/*/address', GLOB_NOSORT) as $addressPath) {
            $mac = trim(file_get_contents($addressPath));
            if (// localhost adapter
                $mac !== '00:00:00:00:00:00' &&
                // must match  mac adress
                preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac)) {
                return str_replace(':', '', $mac);
            }
        }

        return false;
    }
}
