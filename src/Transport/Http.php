<?php

namespace ClickHouseDB\Transport;

use ClickHouseDB\Query\Degeneration;
use ClickHouseDB\Query\Query;
use ClickHouseDB\Query\WhereInFile;
use ClickHouseDB\Query\WriteToFile;
use ClickHouseDB\Settings;
use ClickHouseDB\Statement;

class Http
{
    /**
     * @var string
     */
    private $_username = null;

    /**
     * @var string
     */
    private $_password = null;

    /**
     * @var string
     */
    private $_host = '';

    /**
     * @var int
     */
    private $_port = 0;

    /**
     * @var bool|int
     */
    private $_verbose = false;

    /**
     * @var CurlerRolling
     */
    private $_curler = null;

    /**
     * @var Settings
     */
    private $_settings = null;

    /**
     * @var array
     */
    private $_query_degenerations = [];

    /**
     * Count seconds (int)
     *
     * @var int
     */
    private $_connectTimeOut = 5;

    /**
     * @var callable
     */
    private $xClickHouseProgress = null;

    /**
     * Http constructor.
     * @param string $host
     * @param int $port
     * @param string $username
     * @param string $password
     */
    public function __construct($host, $port, $username, $password)
    {
        $this->setHost($host, $port);

        $this->_username = $username;
        $this->_password = $password;
        $this->_settings = new Settings($this);

        $this->setCurler();
    }


    public function setCurler()
    {
        $this->_curler = new CurlerRolling();
    }

    /**
     * @return CurlerRolling
     */
    public function getCurler()
    {
        return $this->_curler;
    }

    /**
     * @param string $host
     * @param int $port
     */
    public function setHost($host, $port = -1)
    {
        if ($port > 0) {
            $this->_port = $port;
        }

        $this->_host = $host;
    }

    /**
     * @return string
     */
    public function getUri()
    {
        $proto = 'http';
        if ($this->settings()->isHttps()) {
            $proto = 'https';
        }

        return $proto . '://' . $this->_host . ':' . $this->_port;
    }

    /**
     * @return Settings
     */
    public function settings()
    {
        return $this->_settings;
    }

    /**
     * @param bool|int $flag
     * @return mixed
     */
    public function verbose($flag)
    {
        $this->_verbose = $flag;
        return $flag;
    }

    /**
     * @param array $params
     * @return string
     */
    private function getUrl($params = [])
    {
        $settings = $this->settings()->getSettings();

        if (is_array($params) && sizeof($params)) {
            $settings = array_merge($settings, $params);
        }


        if ($this->settings()->isReadOnlyUser())
        {
            unset($settings['extremes']);
            unset($settings['readonly']);
            unset($settings['enable_http_compression']);
            unset($settings['max_execution_time']);

        }

        unset($settings['https']);


        return $this->getUri() . '?' . http_build_query($settings);
    }

    /**
     * @param array $extendinfo
     * @return CurlerRequest
     */
    private function newRequest($extendinfo)
    {
        $new = new CurlerRequest();
        $new->auth($this->_username, $this->_password)
            ->POST()
            ->setRequestExtendedInfo($extendinfo);

        if ($this->settings()->isEnableHttpCompression()) {
            $new->httpCompression(true);
        }
        if ($this->settings()->getSessionId())
        {
            $new->persistent();
        }

        $new->timeOut($this->settings()->getTimeOut());
        $new->connectTimeOut($this->_connectTimeOut)->keepAlive(); // one sec
        $new->verbose(boolval($this->_verbose));

        return $new;
    }

    /**
     * @param Query $query
     * @param array $urlParams
     * @param bool $query_as_string
     * @return CurlerRequest
     * @throws \ClickHouseDB\Exception\TransportException
     */
    private function makeRequest(Query $query, $urlParams = [], $query_as_string = false)
    {
        $sql = $query->toSql();

        if ($query_as_string) {
            $urlParams['query'] = $sql;
        }

        $url = $this->getUrl($urlParams);

        $extendinfo = [
            'sql' => $sql,
            'query' => $query,
            'format'=> $query->getFormat()
        ];

        $new = $this->newRequest($extendinfo);
        $new->url($url);




        if (!$query_as_string) {
            $new->parameters_json($sql);
        }
        if ($this->settings()->isEnableHttpCompression()) {
            $new->httpCompression(true);
        }

        return $new;
    }

    /**
     * @param string $sql
     * @return CurlerRequest
     */
    public function writeStreamData($sql)
    {
        $query = new Query($sql);

        $url = $this->getUrl([
            'readonly' => 0,
            'query' => $query->toSql()
        ]);

        $extendinfo = [
            'sql' => $sql,
            'query' => $query,
            'format'=> $query->getFormat()
        ];

        $request = $this->newRequest($extendinfo);
        $request->url($url);
        return $request;
    }


    /**
     * @param string $sql
     * @param string $file_name
     * @return Statement
     * @throws \ClickHouseDB\Exception\TransportException
     */
    public function writeAsyncCSV($sql, $file_name)
    {
        $query = new Query($sql);

        $url = $this->getUrl([
            'readonly' => 0,
            'query' => $query->toSql()
        ]);

        $extendinfo = [
            'sql' => $sql,
            'query' => $query,
            'format'=> $query->getFormat()
        ];

        $request = $this->newRequest($extendinfo);
        $request->url($url);

        $request->setCallbackFunction(function(CurlerRequest $request) {
            $handle = $request->getInfileHandle();
            if (is_resource($handle)) {
                fclose($handle);
            }
        });

        $request->setInfile($file_name);
        $this->_curler->addQueLoop($request);

        return new Statement($request);
    }

    /**
     * get Count Pending Query in Queue
     *
     * @return int
     */
    public function getCountPendingQueue()
    {
        return $this->_curler->countPending();
    }

    /**
     * set Connect TimeOut in seconds [CURLOPT_CONNECTTIMEOUT] ( int )
     *
     * @param int $connectTimeOut
     */
    public function setConnectTimeOut($connectTimeOut)
    {
        $this->_connectTimeOut = $connectTimeOut;
    }

    /**
     * get ConnectTimeOut in seconds
     *
     * @return int
     */
    public function getConnectTimeOut()
    {
        return $this->_connectTimeOut;
    }


    public function __findXClickHouseProgress($handle)
    {
        $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        // Search X-ClickHouse-Progress
        if ($code == 200) {
            $response = curl_multi_getcontent($handle);
            $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            if (!$header_size) {
                return false;
            }

            $header = substr($response, 0, $header_size);
            if (!$header_size) {
                return false;
            }
            $pos = strrpos($header, 'X-ClickHouse-Progress');

            if (!$pos) {
                return false;
            }

            $last = substr($header, $pos);
            $data = @json_decode(str_ireplace('X-ClickHouse-Progress:', '', $last), true);

            if ($data && is_callable($this->xClickHouseProgress)) {

                if (is_array($this->xClickHouseProgress)) {
                    call_user_func_array($this->xClickHouseProgress, [$data]);
                } else {
                    call_user_func($this->xClickHouseProgress, $data);
                }


            }

        }

    }

    /**
     * @param Query $query
     * @param null|WhereInFile $whereInFile
     * @param null|WriteToFile $writeToFile
     * @return CurlerRequest
     * @throws \Exception
     */
    public function getRequestRead(Query $query, $whereInFile = null, $writeToFile = null)
    {
        $urlParams = ['readonly' => 1];
        $query_as_string = false;
        // ---------------------------------------------------------------------------------
        if ($whereInFile instanceof WhereInFile && $whereInFile->size()) {
            // $request = $this->prepareSelectWhereIn($request, $whereInFile);
            $structure = $whereInFile->fetchUrlParams();
            // $structure = [];
            $urlParams = array_merge($urlParams, $structure);
            $query_as_string = true;
        }
        // ---------------------------------------------------------------------------------
        // if result to file
        if ($writeToFile instanceof WriteToFile && $writeToFile->fetchFormat()) {
            $query->setFormat($writeToFile->fetchFormat());
            unset($urlParams['extremes']);
        }
        // ---------------------------------------------------------------------------------
        // makeRequest read
        $request = $this->makeRequest($query, $urlParams, $query_as_string);
        // ---------------------------------------------------------------------------------
        // attach files
        if ($whereInFile instanceof WhereInFile && $whereInFile->size()) {
            $request->attachFiles($whereInFile->fetchFiles());
        }
        // ---------------------------------------------------------------------------------
        // result to file
        if ($writeToFile instanceof WriteToFile && $writeToFile->fetchFormat()) {

            $fout = fopen($writeToFile->fetchFile(), 'w');
            if (is_resource($fout)) {

                $isGz = $writeToFile->getGzip();

                if ($isGz) {
                    // write gzip header
                    // "\x1f\x8b\x08\x00\x00\x00\x00\x00"
                    // fwrite($fout, "\x1F\x8B\x08\x08".pack("V", time())."\0\xFF", 10);
                    // write the original file name
                    // $oname = str_replace("\0", "", basename($writeToFile->fetchFile()));
                    // fwrite($fout, $oname."\0", 1+strlen($oname));

                    fwrite($fout, "\x1f\x8b\x08\x00\x00\x00\x00\x00");

                }


                $request->setResultFileHandle($fout, $isGz)->setCallbackFunction(function(CurlerRequest $request) {
                    fclose($request->getResultFileHandle());
                });
            }
        }
        if ($this->xClickHouseProgress)
        {
            $request->setFunctionProgress([$this, '__findXClickHouseProgress']);
        }
        // ---------------------------------------------------------------------------------
        return $request;

    }

    public function cleanQueryDegeneration()
    {
        $this->_query_degenerations = [];
        return true;
    }

    public function addQueryDegeneration(Degeneration $degeneration)
    {
        $this->_query_degenerations[] = $degeneration;
        return true;
    }

    /**
     * @param Query $query
     * @return CurlerRequest
     * @throws \ClickHouseDB\Exception\TransportException
     */
    public function getRequestWrite(Query $query)
    {
        $urlParams = ['readonly' => 0];
        return $this->makeRequest($query, $urlParams);
    }

    /**
     * @param string $sql
     * @param array $bindings
     * @return Query
     */
    private function prepareQuery($sql, $bindings)
    {

        // add Degeneration query
        foreach ($this->_query_degenerations as $degeneration) {
            $degeneration->bindParams($bindings);
        }

        return new Query($sql, $this->_query_degenerations);
    }


    /**
     * @param Query|string $sql
     * @param array $bindings
     * @param null|WhereInFile $whereInFile
     * @param null|WriteToFile $writeToFile
     * @return CurlerRequest
     * @throws \Exception
     */
    private function prepareSelect($sql, $bindings, $whereInFile, $writeToFile = null)
    {
        if ($sql instanceof Query) {
            return $this->getRequestWrite($sql);
        }


        $query = $this->prepareQuery($sql, $bindings);
        $query->setFormat('JSON');
        return $this->getRequestRead($query, $whereInFile, $writeToFile);

    }

    /**
     * @param Query|string $sql
     * @param array $bindings
     * @return CurlerRequest
     * @throws \ClickHouseDB\Exception\TransportException
     */
    private function prepareWrite($sql, $bindings = [])
    {
        if ($sql instanceof Query) {
            return $this->getRequestWrite($sql);
        }

        $query = $this->prepareQuery($sql, $bindings);
        return $this->getRequestWrite($query);
    }

    /**
     * @return bool
     * @throws \ClickHouseDB\Exception\TransportException
     */
    public function executeAsync()
    {
        return $this->_curler->execLoopWait();
    }

    /**
     * @param Query|string $sql
     * @param array $bindings
     * @param null|WhereInFile $whereInFile
     * @param null|WriteToFile $writeToFile
     * @return Statement
     * @throws \ClickHouseDB\Exception\TransportException
     * @throws \Exception
     */
    public function select($sql, array $bindings = [], $whereInFile = null, $writeToFile = null)
    {
        $request = $this->prepareSelect($sql, $bindings, $whereInFile, $writeToFile);
        $this->_curler->execOne($request);
        return new Statement($request);
    }

    /**
     * @param Query|string $sql
     * @param array $bindings
     * @param null|WhereInFile $whereInFile
     * @param null|WriteToFile $writeToFile
     * @return Statement
     * @throws \ClickHouseDB\Exception\TransportException
     * @throws \Exception
     */
    public function selectAsync($sql, array $bindings = [], $whereInFile = null, $writeToFile = null)
    {
        $request = $this->prepareSelect($sql, $bindings, $whereInFile, $writeToFile);
        $this->_curler->addQueLoop($request);
        return new Statement($request);
    }

    /**
     * @param callable $callback
     */
    public function setProgressFunction(callable $callback)
    {
        $this->xClickHouseProgress = $callback;
    }

    /**
     * @param string $sql
     * @param array $bindings
     * @param bool $exception
     * @return Statement
     * @throws \ClickHouseDB\Exception\TransportException
     */
    public function write($sql, array $bindings = [], $exception = true)
    {
        $request = $this->prepareWrite($sql, $bindings);
        $this->_curler->execOne($request);
        $response = new Statement($request);
        if ($exception) {
            if ($response->isError()) {
                $response->error();
            }
        }
        return $response;
    }
}
