<?php

declare(strict_types = 1);

namespace App\Application\Controllers;

use function GuzzleHttp\json_decode;
use Fig\Http\Message\StatusCodeInterface as StatusCode;
use Psr\Container\ContainerInterface as Container;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\SimpleCache\InvalidArgumentException;
use GuzzleHttp\Exception\GuzzleException;
use Exception;
use Closure;

class CoursesController
{
    protected $container;
    protected $cache;
    protected $logger;
    protected $settings;
    protected $httpClient;

    protected $errors = [];
    protected $statusCode = StatusCode::STATUS_OK;

    public function __construct(Container $container)
    {
        $this->container  = $container;
        $this->cache      = $container->get('Psr\SimpleCache\CacheInterface');
        $this->logger     = $container->get('Psr\Log\LoggerInterface');
        $this->settings   = $container->get('settings');
        $this->httpClient = $container->get('GuzzleHttp\ClientInterface');
    }

    /**
     * Получение соотношения заданной валюты к доллару
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     *
     * @throws Exception
     */
    public function getCost(Request $request, Response $response) : Response
    {
        $result = $this->requestLogging(function ($request) {
            if ($this->validateRequest($request)) {
                return json_encode([
                    'cost'     => $this->valuteConvert(
                        (float) $request->getQueryParams()['nominal'],
                        $request->getQueryParams()['valute']
                    ),
                    'currency' => strtoupper($request->getQueryParams()['valute']),
                ]);
            } else {
                $this->statusCode = StatusCode::STATUS_BAD_REQUEST;

                return json_encode([
                    'errors' => $this->errors,
                    'status' => StatusCode::STATUS_BAD_REQUEST,
                ]);
            }
        }, $request);

        $response->getBody()->write($result);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($this->statusCode);
    }

    /**
     * "Посредник" для логирования запроса с любым исходом (успех/неудача)
     *
     * @param Closure $callback
     * @param Request $request
     *
     * @return string
     *
     * @throws Exception
     */
    protected function requestLogging(Closure $callback, Request $request) : string
    {
        try {
            $result = call_user_func($callback, $request);

            $this->logWrite($request);
        } catch (Exception $e) {        // Перехват исключения, для логирования даже ошибочного запроса
            $this->logWrite($request);

            throw $e;                   // "Проброс" исключения для глобального обработчика фреймворка
        }

        return $result;
    }

    /**
     * Валидация параметор запроса
     *
     * В реальном проекте желательно подключить стороннюю библиотеку
     * типа https://packagist.org/packages/beberlei/assert
     *
     * @param Request $request
     *
     * @return boolean
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    protected function validateRequest(Request $request) : bool
    {
        $params = $request->getQueryParams();

        if (empty($params['valute']) || !isset($params['nominal'])) {
            $this->errors[] = 'Missing required parameters "valute" or "nominal"';
        }

        $coursesAssoc = (array) $this->getCourses()->Valute;
        $valute = strtoupper($params['valute']);

        if (! isset($coursesAssoc[$valute]) && 'RUB' != $valute) {
            $this->errors[] = 'Value "' . $params['valute'] . '" not found in currency list';
        }

        if ((int) $params['nominal'] <= 0) {
            $this->errors[] = 'Value "' . $params['nominal'] . '" must be a number greater than zero';
        }

        return $this->errors ? false : true;
    }

    /**
     * Получение курсов валют по отношению к рублю из внешнего источника или из кэша
     *
     * "Прогревать" кэш нужно в "фоне". В реал-тайме сделано для упрощения логики, чтобы не писать скрипт для крона
     * по идее в "ветку" else попадать не должен при правильной работе крон скрипта
     *
     * Для кэширования был выбран класс из следующей библиотеки
     * https://github.com/thephpleague/uri-hostname-parser/blob/master/src/PublicSuffix/Cache.php
     *
     * @return mixed
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    protected function getCourses() : \stdClass
    {
        if ($this->cache->has('courses')) {
            $courses = $this->cache->get('courses');
        } else {
            // Источник установил лимиты:
            // 5 запросов в секунду, 120 запросов в минуту и не более 10000 запросов в сутки
            $response = $this->httpClient->request('GET', $this->settings['coursesUrl']);

            $courses = json_decode((string) $response->getBody());

            $this->cache->set('courses', $courses, $this->settings['cacheTime']);
        }

        return $courses;
    }

    /**
     * Логирование запроса на получение курсов валют
     *
     * Лог пишется в общий лог-файл проекта, чтобы сэкономить время на написании миграций
     * и последующем подъеме рабочего окружения. Использование стороннего функционала ORM
     * сложности особой не представляет
     *
     * Для высоко нагруженных сервисов лог нужно копить в каком-то буфере и писать порциями,
     * чтобы не создавать нагрузку на файловую систему или другое хранилище
     *
     * @param Request $request
     *
     * @return void
     */
    protected function logWrite(Request $request)
    {
        $server = $request->getServerParams();

        $this->logger->info('Request logging', [
            'requestUri' => $server['REQUEST_URI'],               // Содержимое запроса
            'leadTime'   => (microtime(true) - $server['REQUEST_TIME_FLOAT'])
                * 1000,                                           // Длительность запроса в миллисекундах
            'userAgent'  => $server['HTTP_USER_AGENT'],           // Информация о клиенте
            'userIp'     => $server['REMOTE_ADDR'],               // Ip-адрес
            'timeStamp'  => time(),                               // В какое время был сделан запрос
        ]);
    }

    /**
     * Конвертация заданной валюты и номинала в доллары США (USD)
     *
     * @param float $nominal
     * @param string $from
     *
     * @return float|null
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    protected function valuteConvert(float $nominal, string $from) : ?float
    {
        $coursesAssoc = (array) $this->getCourses()->Valute;

        $code = strtoupper($from);

        if ($nominal > 0 && isset($coursesAssoc[$code])) {
            return $coursesAssoc[$code]->Value * $nominal / $coursesAssoc['USD']->Value;
        } elseif ($nominal > 0 && 'RUB' == $code) { // В источнике валюты в рублях, поэтому добавлено доп. условие
            return $nominal / $coursesAssoc['USD']->Value;
        }
    }
}
