<?php declare(strict_types=1);
namespace OpenAPI\Generator\Documentation;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Psr7\Uri;
use Onion\Framework\Console\Interfaces\CommandInterface;
use Onion\Framework\Console\Interfaces\ConsoleInterface;
use OpenAPI\Readers\JsonReader;
use OpenAPI\Spec\Entities\Components\Example;
use OpenAPI\Spec\Entities\Components\ExternalDoc;
use OpenAPI\Spec\Entities\Components\Header;
use OpenAPI\Spec\Entities\Components\MediaType;
use OpenAPI\Spec\Entities\Components\Operation;
use OpenAPI\Spec\Entities\Components\Param;
use OpenAPI\Spec\Entities\Components\Property;
use OpenAPI\Spec\Entities\Components\ReferenceObject;
use OpenAPI\Spec\Entities\Components\Response;
use OpenAPI\Spec\Entities\Components\Schema;
use OpenAPI\Spec\Entities\Document;
use OpenAPI\Spec\Entities\Info;
use OpenAPI\Spec\Entities\Information\Contact;
use OpenAPI\Spec\Entities\Information\License;
use OpenAPI\Spec\Entities\Path;
use OpenAPI\Spec\Entities\Security;
use OpenAPI\Spec\Entities\Server;
use OpenAPI\Spec\V3\Parser;
use OpenAPI\Spec\V3\Serializer;
use Psr\Container\ContainerInterface;

class Generate implements CommandInterface
{
    public function trigger(ConsoleInterface $console): int
    {
        $target = $console->getArgument('directory', getcwd()) . '/' .
            $console->getArgument('filename', 'swagger.json');

        if (!file_exists(getcwd() . '/container.generated.php')) {
            $console->writeLine(
                '%text:red%Compiled container file not found'
            );
            return 1;
        }

        /** @var ContainerInterface $compiledContainer */
        $compiledContainer = include_once(getcwd() . '/container.generated.php');

        if (file_exists($target)) {
            $parser = new Parser(new JsonReader);
            $document = $parser->parse($target);
        } else {
            $info = new Info();
            $info->setTitle($console->prompt('Title', 'My API'));
            $info->setVersion($console->prompt('Version', '1.0.0'));

            $contact = new Contact();
            $contact->setEmail($console->prompt('Contact Email', ''));
            $contact->setName($console->prompt('Contact Name', ''));
            $contact->setUrl($console->prompt('Contact URL', ''));
            $info->setContact($contact);

            $license = new License($console->prompt('License', 'proprietary'));
            $license->setUrl($console->prompt('License URL', ''));
            $info->setLicense($license);
            $document = new Document($info);
        }

        $authorization = false;
        if (!$document->hasSecurity()) {
            if (!$console->confirm('Is the API public', 'n')) {
                $security = new Security('auth');
                $security->setType(
                    $console->choice('Authentication type', [
                        'http',
                        'apiKey',
                        'openIdConnect',
                    ], 'http')
                );

                switch ($security->getType()) {
                    case 'http':
                        $security->setScheme($console->choice('Via', [
                                'basic',
                                'digest',
                                'bearer',
                            ], 'basic'));
                        if ($security->getScheme() === 'bearer') {
                            $security->setBearerFormat($console->prompt('Bearer format', 'Bearer'));
                        }
                        break;
                    case 'apiKey':
                        $place = $console->choice('Token provided by', ['cookie', 'query', 'header'], 'header');
                        $security->setPlace($place);
                        $place = ucfirst($place);
                        $security->setName($console->prompt("{$place} name"));
                        break;
                    case 'openIdConnect':
                        $security->setOpenIdConnectUrl(
                            $console->prompt('OpenID Connect URL')
                        );
                        $console->writeLine(
                            '%text:yellow%Unable to generate responses, please add them manually'
                        );
                        break;
                }
                $document->addComponent($security);
                $document->addSecurity($security->getName(), []);
            }
        }

        $sec = $document->getComponents()['securitySchemes'] ?? [false];
        $security = array_shift($sec);
        if ($security) {
            switch ($security->getType()) {
                case 'http':
                    if ($security->getScheme() === 'bearer') {
                        $authorization = [
                            'headers' => [
                                'Authorization' => "{$security->getBearerFormat()} " .
                                    $console->prompt('Token'),
                            ],
                        ];
                        break;
                    }

                    $authorization = [
                        'auth' => [
                            $console->prompt('Username'),
                            $console->password('Password'),
                        ],
                    ];

                    if ($security->getScheme() === 'digest') {
                        $authorization['auth'][] = 'digest';
                    }
                    break;
                case 'apiKey':
                    $place = ucfirst($security->getScheme());
                    $security->setName($console->prompt("{$place} name"));

                    switch ($security->getPlace()) {
                        case 'cookie':
                            $jar = new CookieJar();
                            $jar->setCookie(
                                new SetCookie([
                                    'Name' => $security->getName(),
                                    'Value' => $console->password('Cookie value'),
                                    'HttpOnly' => true,
                                    'Domain' => $console->prompt('Cookie domain'),
                                    'Expires' => time() + 3600,
                                ])
                            );

                            $authorization = [
                                'cookies' => $jar,
                            ];
                            break;
                        case 'query':
                            $authorization = [
                                $security->getName() => $console->password('Parameter value'),
                            ];
                            break;
                        case 'header':
                            $authorization = [
                                'headers' => [
                                    $security->getName() => $console->prompt('Header value'),
                                ],
                            ];
                            break;
                    }
                    break;
                case 'openIdConnect':
                    $console->writeLine(
                        '%text:yellow%Unable to generate responses, please add them manually'
                    );
                    break;
            }
        }

        $servers = $document->getServers();
        if (!$servers[0] ?? false) {
            $server = new Server($console->prompt('Base URL of the API'));
            $document->addServer($server);
        }

        $client = new Client([
            'base_uri' => $document->getServers()[0]->getUrl(),
        ]);

        foreach ($compiledContainer->get('routes') as $route) {
            if ($document->getPath($route['pattern']) !== null) {
                continue;
            }

            $path = new Path($route['pattern']);
            $console->writeLine("Processing: {$path->getName()}");

            foreach ($route['headers'] ?? [] as $header => $required) {
                $parameter = new Param($header);
                $parameter->setRequired($required);
                $parameter->setPlace('header');
                $parameter->setType('string');
            }

            $params = [];
            if (preg_match_all('~(?:\{((\w+)(\:(.*))?)\})~iuU', $path->getName(), $pathParams) > 0) {
                foreach ($pathParams[1] ?? [] as $param) {
                    $parts = explode(':', $param, 2);
                    $parts[] = '';
                    list($name, $constraint)=$parts;
                    $params["{{$param}}"] = '';
                    $parameter = new Param($name);
                    $parameter->setRequired(true);
                    $parameter->setPlace('path');
                    $parameter->setType('string');
                    switch ($constraint) {
                        case '\d+':
                        case '\d':
                        case '[0-9]+':
                        case '[0-9]':
                            $parameter->setType('integer');
                            $parameter->setFormat('int32');
                            break;
                    }

                    $path->addParameter($parameter);
                }
            }

            foreach ($route['methods'] ?? [] as $method) {
                $operation = new Operation("{$method}");
                $operation->setOperationId(
                    $console->prompt("Operation Name [{$method} {$path->getName()}]")
                );
                array_walk($params, function (&$param, $key) use ($console) {
                    $param = $console->prompt("Value to use for {$key}");
                });
                $response = $client->request(
                    $method,
                    strtr($path->getName(), $params),
                    array_merge([
                        'connect_timeout' => 5,
                    ], ($authorization ?: []))
                );

                $resp = new Response("{$response->getStatusCode()}");
                if (($body = json_decode((string) $response->getBody(), true)) !== false) {
                    $payload = $this->handleSchema(
                        $console->prompt("Name for the response of {$path->getName()}"),
                        $body,
                        $document
                    );
                    $document->addComponent($payload);

                    $resp->addContent(
                        $response->hasHeader('content-type') ?
                            explode(';', $response->getHeaderLine('content-type'))[0] :
                            'application/json',
                        new MediaType(
                            new ReferenceObject("#/components/schemas/{$payload->getName()}")
                        )
                    );
                }

                foreach ($response->getHeaders() as $header => $v) {
                    if (stripos($header, 'x-') !== 0) {
                        continue;
                    }
                    $h = new Header($header);
                    $h->setType('string');
                    if (preg_match('~^[0-9]+$~', $response->getHeaderLine($header))) {
                        $h->setType('integer');
                        $h->setFormat('int32');
                    }

                    if (preg_match('~^[0-9]+\.[0-9]+$~', $response->getHeaderLine($header))) {
                        $h->setType('double');
                    }

                    $resp->addHeader($h);
                }

                $operation->addResponse($resp->getName(), $resp);

                $path->addOperation($operation);
            }
            $document->addPath($path);
        }

        if (!$document->hasExternalDoc()) {
            $document->setExternalDoc(
                new ExternalDoc($console->prompt('External Documentation URL'))
            );
        }

        $serializer = new Serializer($document);
        file_put_contents($target, json_encode($serializer->serialize(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return 0;
    }

    private function handleSchema(string $name, $body, Document $document): Schema
    {
        $object = new Schema($name);
        $object->setType('object');
        foreach ($body as $property => $value) {
            if (is_int($property)) {
                $object->setType('array');
                $object->setFormat("#/components/schemas/{$name}Item");

                if (!$document->hasSchema("{$name}Item")) {
                    $document->addComponent(
                        $this->handleSchema("{$name}Item", $value, $document)
                    );
                }
                break;
            }

            $object->addProperty($this->handleProperty($property, $value, $document));
        }

        return $object;
    }

    private function handleProperty(string $name, $value, Document $document): Property
    {
        $prop = new Property($name, 'string');
        $prop->addExample(new Example($value));
        if (is_int($value)) {
            $prop->setType('integer');
            $prop->setFormat('int32');
        }

        if (is_float($value)) {
            $prop->setType('double');
        }

        if (is_array($value)) {
            if (isset($value[0])) {
                $prop->setType('array');
                $prop->setFormat(gettype($value[0]));
            } else {
                $prop->setType('object');
                if (!$document->hasSchema("{$prop->getName()}Item")) {
                    $document->addComponent(
                        $this->handleSchema("{$prop->getName()}Item", $value, $document)
                    );
                }
                $prop->setFormat("#/components/schemas/{$prop->getName()}Item");
            }
        }

        return $prop;
    }
}
