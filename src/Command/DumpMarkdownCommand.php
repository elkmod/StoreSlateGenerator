<?php

namespace GenerateApiReference\Command;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\StoreApiGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelDefinitionInstanceRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DumpMarkdownCommand extends Command
{
    protected static $defaultName = 'api:dump:markdown';
    protected static $version = PlatformRequest::API_VERSION;

    /**
     * @var StoreApiGenerator
     */
    private $generator;

    /**
     * @var SalesChannelDefinitionInstanceRegistry
     */
    private $salesChannelDefinitionRegistry;

    private $schema = [];

    public function __construct(StoreApiGenerator $generator, SalesChannelDefinitionInstanceRegistry $salesChannelDefinitionRegistry)
    {
        parent::__construct(self::$defaultName);

        $this->generator = $generator;
        $this->salesChannelDefinitionRegistry = $salesChannelDefinitionRegistry;
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        //output->writeln('Dumping API Markdown');

        /** @var EntityDefinition[] $definitions */
        $definitions = $this->salesChannelDefinitionRegistry->getDefinitions();

        $this->schema = $this->generator->generate($definitions, self::$version, 'store-api');

        //dd(($this->schema['paths']['/handle-payment']['get']));

        $output->write($this->parseToMarkdown($this->schema['paths']));

        return 0;
    }

    private function parseToMarkdown(array $endpoints): string
    {
        $output = '';
        $version = self::$version;

        foreach($endpoints as $endpoint => $methods) {

            $output .= <<<EOD
# $endpoint


EOD;


            foreach ($methods as $method => $meta) {
                $method = strtoupper($method);

                $output .= <<<EOD
## {$meta['operationId']}

> **Responses**


EOD;

                if(array_key_exists('responses', $meta)) {
                    $output .= $this->parseResponses($meta['responses']);
                }

                $output .= <<<EOD


{$meta['description']}

### HTTP Request

`$method http://example.com/store-api/v{$version}$endpoint`

EOD;
                if(array_key_exists('parameters', $meta)) {

                    $output .= <<<EOD
### Parameters:


EOD;

                    $output .= $this->parseParameters($meta['parameters']) . "\n";
                }
            }
        }

        return $output;

    }

    private function parseParameters($parameters): string
    {
        $output = '';

        $output .= <<<EOD
Parameter | Type | In | Required | Description
----- | ----- | -----| ----- | -----

EOD;

        foreach($parameters as $parameter) {
            if(!array_key_exists('type', $parameter['schema'])) {
                $parameterType = $parameter['schema']['$ref'];
            } else {
                $parameterType = $parameter['schema']['type'];
            }
            $output .= '**' . $parameter['name'] . '**' . ' | ' .
                ( $parameterType ?? '' ) . ' | ' .
                ( $parameter['in'] ?? '' ) . ' | ' .
                ( ( $parameter['required'] ?? false ) ? 'yes' : 'no' ) . ' | ' .
                $parameter['description'] . "\n";
        }

        return $output;
    }

    private function parseResponses($responses): string
    {
        $output = '';

        foreach ($responses as $code => $response) {
            $description = $response['description'] ?? '';
            $output .= "\n\n> $code - $description\n\n";

            if(array_key_exists('content', $response)) {
                $output .= $this->parseSchema($response['content']['application/json']['schema']);
            }
        }

        return $output;
    }

    private function parseSchema($schema): string
    {
        $output = '';

        if(array_key_exists('$ref', $schema)) {

            $def = explode('/', substr($schema['$ref'], 2));

            if($def[0] == 'definitions') {
                $output .= "```json\n" . \GuzzleHttp\json_encode(
                        $this->resolveDefinition(
                            $this->schema[$def[0]][$def[1]]
                        ), JSON_PRETTY_PRINT
                    ) . "\n```";
            }

            if($def[0] == 'components') {
                $output .= "```json\n" . \GuzzleHttp\json_encode(
                        $this->resolveDefinition(
                            $this->schema[$def[0]][$def[1]][$def[2]]
                        ), JSON_PRETTY_PRINT
                    ) . "\n```";
            }

        } else {
            $output .= "```json\n" . \GuzzleHttp\json_encode(
                    $this->resolveDefinition(
                        $schema
                    ), JSON_PRETTY_PRINT
                ) . "\n```";
        }

        return $output;
    }

    private function resolveDefinition($defintion)
    {
        if(!array_key_exists('type', $defintion)) {
            return $defintion['$ref'];
        }

        switch ($defintion['type']) {
            case 'object': if(array_key_exists('properties', $defintion)) {
                return array_map(function ($property) {
                            return $this->resolveDefinition($property);
                        }, $defintion['properties']
                    );
                } else {
                    return 'object';
                }
                break;
            case 'array': return []; break;
            case 'string': return 'some-string'; break;
            case 'integer': return 42; break;
            case 'boolean': return true; break;
            case 'float': return 17.29; break;
            default: return $defintion['type'];
        }
    }

    private function resolveObjectDefinition($object)
    {

    }
}
