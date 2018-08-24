<?php

namespace Playbloom\Satisfy\Controller;

use Smalot\Bitbucket\Webhook\Model\RepoPushModel;
use Smalot\Bitbucket\Webhook\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class BitbucketController extends Controller
{
    public function webhookAction(Request $request)
    {
        if (!$this->checkIsBitbucketRequest($request)) {
            return new Response('Invalid webhook data', Response::HTTP_BAD_REQUEST);
        }
        $json = json_decode($request->getContent(), true);
        $fullName = '';
        if (isset($json['data']['repository']['full_name'])) {
            $fullName = $json['data']['repository']['full_name'];
        } elseif (isset($json['repository']['full_name'])) {
            $fullName = $json['repository']['full_name'];
        }
        if (empty($fullName)) {
            return new Response('Invalid webhook data (repository.full_name is missing) ', 400);
        }

        ini_set('implicit_flush', 1);
        ob_implicit_flush(true);

        $path = $this->container->getParameter('kernel.project_dir');
        $env = $this->getDefaultEnv();
        $env['HOME'] = $this->container->getParameter('composer.home');

        $arguments = $this->container->getParameter('satis_filename');
        $arguments .= ' --repository-url=git@bitbucket.org/' . $fullName . '.git';
        $arguments .= ' --skip-errors --no-ansi --no-interaction --verbose';

        $process = new Process($path . '/bin/satis build', $path, $env, $arguments, 600);
        $process->start();

        $processRead = function () use ($process) {
            $print = function ($data) {
                $data = trim($data);
                if (empty($data)) {
                    return;
                }
                echo 'data: ', $data, PHP_EOL, PHP_EOL;
            };
            $print('$ ' . $process->getCommandLine() . ' ' . $process->getInput());
            foreach ($process as $content) {
                $print($content);
            }
            $print($process->getExitCodeText());
            $print('__done__');
        };

        return new StreamedResponse($processRead, Response::HTTP_OK, ['Content-Type' => 'text/event-stream']);
    }

    /**
     * @param Request $request
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    protected function checkIsBitbucketRequest(Request $request)
    {
        $trustedIpRanges = array(
            '131.103.20.160/27',
            '165.254.145.0/26',
            '104.192.143.0/21',
            '127.0.0.1/32',
            '18.205.93.0/25',
            '18.234.32.128/25',
            '13.52.5.0/25',
        );

        // Extract Bitbucket headers from request.
        $requestUuid = (string)$request->headers->get('X-Request-Uuid');
        $event = (string)$request->headers->get('X-Event-Key');
        $count = (int)$request->headers->get('X-Attempt-Number');

        if (empty($requestUuid) || empty($event) || empty($count)) {
            throw new \InvalidArgumentException('Missing Bitbucket headers.');
        }

        if ($this->ipInRanges($request->getClientIp(), $trustedIpRanges)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $ip
     * @param array $ranges
     * @return bool
     */
    protected function ipInRanges($ip, $ranges)
    {
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a given ip is in a network
     * @link https://gist.github.com/tott/7684443
     *
     * @param  string $ip IP to check in IPV4 format eg. 127.0.0.1
     * @param  string $range IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32 assumed
     * @return boolean true if the ip is in this range / false if not.
     */
    protected function ipInRange($ip, $range)
    {
        // $range is in IP/CIDR format eg 127.0.0.1/24
        list($range, $netmask) = explode('/', $range, 2);
        $range_decimal = ip2long($range);
        $ip_decimal = ip2long($ip);
        $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
        $netmask_decimal = ~$wildcard_decimal;

        return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
    }

    /**
     * @return array
     */
    private function getDefaultEnv(): array
    {
        $env = [];

        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && false !== $v = getenv($k)) {
                $env[$k] = $v;
            }
        }

        foreach ($_ENV as $k => $v) {
            if (is_string($v)) {
                $env[$k] = $v;
            }
        }

        return $env;
    }
}
