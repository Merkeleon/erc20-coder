<?php
/**
 * Created by PhpStorm.
 * User: chulim
 * Date: 12/6/18
 * Time: 6:20 AM
 */

namespace Merkeleon\Coder;

use Illuminate\Support\Arr;
use Merkeleon\Coder\Exceptions\CoderException;
use Web3\
{
    Utils, Web3, Contract
};

class Coder
{
    protected $contract;
    protected $ethAbi;

    /**
     * Coder constructor.
     * @param string $connection
     * @param string $contractAddress
     */
    public function __construct(string $connection, string $contractAddress)
    {
        $web3 = new Web3($connection);
        $abi  = config('abi.erc20');

        $this->contract = new Contract($web3->getProvider(), $abi);
        $this->contract->at($contractAddress);

        $this->ethAbi = $this->contract->ethabi;
    }

    /**
     * @param array $logs
     * @return array
     */
    public function decodeLog(array $log): array
    {
        $eventInputs = [];
        $data        = Arr::get($log, 'data');
        $topics      = array_slice(Arr::get($log, 'topics', []), 1);
        $topicId     = substr(Arr::first(Arr::get($log, 'topics', [])), 2);

        if (empty($topics) || is_null($data))
        {
            return [];
        }

        foreach ($this->contract->getEvents() as $event)
        {
            $id               = $this->encodeEventSignature($event);
            $eventInputs[$id] = $event;
        }

        if ($topicId && isset($eventInputs[$topicId]))
        {
            $inputs      = Arr::get($eventInputs[$topicId], 'inputs');
            $parsedEvent = $this->decodeEvent($inputs, $topics, $data);
            $parsedEvent['eventName'] = $eventInputs[$topicId]['name'];
        }

        return $parsedEvent ?? [];
    }

    /**
     * @param array $logs
     * @return array
     */
    public function decodeLogs(array $logs): array
    {
        $parsedEvents = [];
        $log = Arr::get($logs, 0, []);
        $decodedLog = $this->decodeLog($log);
        if (empty($decodedLog)) {
            return $parsedEvents;
        }
        $parsedEvents[] = $decodedLog;

        return $parsedEvents;
    }

    /**
     * @param array $logs
     * @return array
     */
    public function decodeFullLogs(array $logs): array
    {
        $parsedEvents = [];
        foreach ($logs as $log) {
            $decodedLog = $this->decodeLog($log);
            if (empty($decodedLog)) {
                continue;
            }
            $parsedEvents[] = $decodedLog;
        }

        return $parsedEvents;
    }

    /**
     * @param array $event
     * @return bool|string
     */
    private function encodeEventSignature(array $event)
    {
        $rawSignature = Arr::get($event, 'name') . '(';
        $types        = [];

        foreach (Arr::get($event, 'inputs', []) as $i => $input)
        {
            $types[$i] = Arr::get($input, 'type');
        }

        $rawSignature .= join(',', $types) . ')';

        return substr(Utils::sha3($rawSignature), 2);
    }

    /**
     * @param array $inputs
     * @param array $topics
     * @param string $data
     * @return array
     */
    private function decodeEvent(array $inputs, array $topics, string $data): array
    {
        $result = [];
        foreach ($inputs as $i => $input)
        {
            $type = Arr::get($input, 'type');
            $name = Arr::get($input, 'name');

            if ($type === 'address')
            {
                $result[$name] = $this->ethAbi->decodeParameter($type, $topics[$i]);
            }

            if ($type === 'uint256')
            {
                $rawValue        = $this->ethAbi->decodeParameter($type, $data);
                $result['value'] = $rawValue->toString();
            }
        }

        return $result;
    }

    /**
     * @param string $holderAddress
     * @return string
     */
    public function getBalance(string $holderAddress): string
    {
        $response = '0';
        $this->contract->call('balanceOf', $holderAddress, function ($err, $result) use (&$response) {
            if (!is_null($err))
            {
                logger()->error('Coder: getBalance - ' . $err);

                return;
            }
            $response = Arr::first($result)->toString();
        });

        return $response;
    }

    /**
     * @param string $ownerAddress
     * @param string $spenderAddress
     * @return string
     */
    public function getAllowance(string $ownerAddress, string $spenderAddress): string
    {
        $response = '0';
        $this->contract->call('allowance', $ownerAddress, $spenderAddress, function ($err, $result) use (&$response) {
            if (!is_null($err))
            {
                logger()->error('Coder: allowance - ' . $err);

                return;
            }

            $response = Arr::first($result)->toString();
        });

        return $response;
    }

    /**
     * @param string $method
     * @param array $params
     * @return string
     * @throws CoderException
     */
    public function encodeMethod(string $method, array $params): string
    {
        $function   = $this->contract->functions[$method];
        if (count($params) !== count($function['inputs']))
        {
            throw new CoderException('Incorrect number parameters for method transferFrom.');
        }

        $data = $this->ethAbi->encodeParameters($function, $params);

        $functionSignature = $this->encodeFunctionSignature($method);


        $code = $functionSignature . Utils::stripZero($data);

        return $code;
    }

    /**
     * @param string $method
     * @return bool|string
     */
    private function encodeFunctionSignature(string $method)
    {
        $function     = $this->contract->functions[$method];
        $rawSignature = $method . '(';
        $types        = [];

        foreach (Arr::get($function, 'inputs', []) as $i => $input)
        {
            $types[$i] = Arr::get($input, 'type');
        }

        $rawSignature .= join(',', $types) . ')';

        return substr(Utils::sha3($rawSignature), 0, 10);
    }
}
