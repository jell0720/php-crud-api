<?php

namespace Tqdev\PhpCrudApi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tqdev\PhpCrudApi\Column\ReflectionService;
use Tqdev\PhpCrudApi\Controller\Responder;
use Tqdev\PhpCrudApi\Database\GenericDB;
use Tqdev\PhpCrudApi\Middleware\Base\Middleware;
use Tqdev\PhpCrudApi\Middleware\Router\Router;


class ConnectCommandMiddleware extends Middleware
{

    private $reflection;
    private $db;
    private $commands = [];

    public function __construct(Router $router, Responder $responder, array $properties, ReflectionService $reflection, GenericDB $db)
    {
        parent::__construct($router, $responder, $properties);
        $this->reflection = $reflection;
        $this->db = $db;

        foreach($this->getCommands() as $command) {
            $this->db->addConnectCommand($command);
        }
    }

    private function getCommands(): array
    {
        $dsnHandler = $this->getProperty('handler', '');
        if ($dsnHandler) {
            return call_user_func($dsnHandler);
        }
        return [];
    }


    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        return $next->handle($request);
    }


}
