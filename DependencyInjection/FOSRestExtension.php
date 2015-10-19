<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\DependencyInjection;

use Symfony\Bundle\WebProfilerBundle\DependencyInjection\Configuration as ProfilerConfiguration;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\HttpFoundation\Response;

class FOSRestExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Default sensio_framework_extra { view: { annotations: false } }.
     *
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container)
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $parameterBag = $container->getParameterBag();
        $configs = $parameterBag->resolveValue($configs);
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($config['view']['view_response_listener']['enabled']) {
            $container->prependExtensionConfig('sensio_framework_extra', ['view' => ['annotations' => false]]);
        }
    }

    /**
     * Loads the services based on your application configuration.
     *
     * @param array            $configs
     * @param ContainerBuilder $container
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('context_adapters.xml');
        $loader->load('view.xml');
        $loader->load('routing.xml');
        $loader->load('request.xml');

        $container->getDefinition('fos_rest.routing.loader.controller')->replaceArgument(4, $config['routing_loader']['default_format']);
        $container->getDefinition('fos_rest.routing.loader.yaml_collection')->replaceArgument(4, $config['routing_loader']['default_format']);
        $container->getDefinition('fos_rest.routing.loader.xml_collection')->replaceArgument(4, $config['routing_loader']['default_format']);

        $container->getDefinition('fos_rest.routing.loader.yaml_collection')->replaceArgument(2, $config['routing_loader']['include_format']);
        $container->getDefinition('fos_rest.routing.loader.xml_collection')->replaceArgument(2, $config['routing_loader']['include_format']);
        $container->getDefinition('fos_rest.routing.loader.reader.action')->replaceArgument(3, $config['routing_loader']['include_format']);

        // The validator service alias is only set if validation is enabled for the request body converter
        $validator = $config['service']['validator'];
        unset($config['service']['validator']);

        foreach ($config['service'] as $key => $service) {
            if (null !== $service) {
                $container->setAlias('fos_rest.'.$key, $service);
            }
        }

        $this->loadForm($config, $loader, $container);
        $this->loadSerializer($config, $container);
        $this->loadException($config, $loader, $container);
        $this->loadBodyConverter($config, $validator, $loader, $container);
        $this->loadView($config, $loader, $container);

        $this->loadBodyListener($config, $loader, $container);
        $this->loadFormatListener($config, $loader, $container, $configs);
        $this->loadParamFetcherListener($config, $loader, $container);
        $this->loadAllowedMethodsListener($config, $loader, $container);
        $this->loadAccessDeniedListener($config, $loader, $container);
    }

    private function loadForm(array $config, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if (!empty($config['disable_csrf_role'])) {
            $loader->load('forms.xml');
            $container->getDefinition('fos_rest.form.extension.csrf_disable')->replaceArgument(1, $config['disable_csrf_role']);
        }
    }

    private function loadAccessDeniedListener(array $config, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if ($config['access_denied_listener']['enabled'] && !empty($config['access_denied_listener']['formats'])) {
            $loader->load('access_denied_listener.xml');

            $service = $container->getDefinition('fos_rest.access_denied_listener');

            if (!empty($config['access_denied_listener']['service'])) {
                $service->clearTag('kernel.event_listener');
            }

            $service->replaceArgument(0, $config['access_denied_listener']['formats']);
            $service->replaceArgument(1, $config['unauthorized_challenge']);
        }
    }

    public function loadAllowedMethodsListener(array $config, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if ($config['allowed_methods_listener']['enabled']) {
            if (!empty($config['allowed_methods_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.allowed_methods_listener');
                $service->clearTag('kernel.event_listener');
            }

            $loader->load('allowed_methods_listener.xml');

            $container->getDefinition('fos_rest.allowed_methods_loader')->replaceArgument(1, $config['cache_dir']);
        }
    }

    private function loadBodyListener(array $config, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if ($config['body_listener']['enabled']) {
            $loader->load('body_listener.xml');

            $service = $container->getDefinition('fos_rest.body_listener');

            if (!empty($config['body_listener']['service'])) {
                $service->clearTag('kernel.event_listener');
            }

            $service->replaceArgument(1, $config['body_listener']['throw_exception_on_unsupported_content_type']);
            $service->addMethodCall('setDefaultFormat', array($config['body_listener']['default_format']));

            $container->getDefinition('fos_rest.decoder_provider')->replaceArgument(0, $config['body_listener']['decoders']);

            $arrayNormalizer = $config['body_listener']['array_normalizer'];

            if (null !== $arrayNormalizer['service']) {
                $bodyListener = $container->getDefinition('fos_rest.body_listener');
                $bodyListener->addArgument(new Reference($arrayNormalizer['service']));
                $bodyListener->addArgument($arrayNormalizer['forms']);
            }
        }
    }

    private function loadFormatListener(array $config, XmlFileLoader $loader, ContainerBuilder $container, array $configs)
    {
        if ($config['format_listener']['enabled'] && !empty($config['format_listener']['rules'])) {
            $loader->load('format_listener.xml');

            if (!empty($config['format_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.format_listener');
                $service->clearTag('kernel.event_listener');
            }

            foreach ($config['format_listener']['rules'] as &$rule) {
                if (!isset($rule['exception_fallback_format'])) {
                    $rule['exception_fallback_format'] = $rule['fallback_format'];
                }
                $this->addFormatListenerRule($rule, $config, $container);
            }

            $bundles = $container->getParameter('kernel.bundles');
            if (isset($bundles['WebProfilerBundle'])) {
                $profilerConfig = $this->processConfiguration(new ProfilerConfiguration(), $configs);

                if ($profilerConfig['toolbar'] || $profilerConfig['intercept_redirects']) {
                    $path = '_profiler';
                    if ($profilerConfig['toolbar']) {
                        $path .= '|_wdt';
                    }

                    $profilerRule = [
                        'host' => null,
                        'methods' => null,
                        'path' => "^/$path/",
                        'priorities' => ['html', 'json'],
                        'fallback_format' => 'html',
                        'exception_fallback_format' => 'html',
                        'prefer_extension' => true,
                    ];

                    $this->addFormatListenerRule($profilerRule, $config, $container);
                }
            }

            if (!empty($config['format_listener']['media_type']['enabled']) && !empty($config['format_listener']['media_type']['version_regex'])) {
                $versionListener = $container->getDefinition('fos_rest.version_listener');
                $versionListener->replaceArgument(1, $config['format_listener']['media_type']['default_version']);
                $versionListener->addMethodCall('setRegex', array($config['format_listener']['media_type']['version_regex']));

                if (!empty($config['format_listener']['media_type']['service'])) {
                    $service = $container->getDefinition('fos_rest.version_listener');
                    $service->clearTag('kernel.event_listener');
                }
            } else {
                $container->removeDefinition('fos_rest.version_listener');
            }

            if ($config['view']['mime_types']['enabled']) {
                $container->getDefinition('fos_rest.format_negotiator')->replaceArgument(1, $config['view']['mime_types']['formats']);
            }
        }
    }

    private function addFormatListenerRule(array $rule, array $config, ContainerBuilder $container)
    {
        $matcher = $this->createRequestMatcher(
            $container,
            $rule['path'],
            $rule['host'],
            $rule['methods']
        );

        unset($rule['path'], $rule['host']);
        if (is_bool($rule['prefer_extension']) && $rule['prefer_extension']) {
            $rule['prefer_extension'] = '2.0';
        }

        $exceptionFallbackFormat = $rule['exception_fallback_format'];
        unset($rule['exception_fallback_format']);
        $container->getDefinition('fos_rest.format_negotiator')
            ->addMethodCall('add', [$matcher, $rule]);

        $rule['fallback_format'] = $exceptionFallbackFormat;
        if ($config['exception']['enabled']) {
            $container->getDefinition('fos_rest.exception_format_negotiator')
            ->addMethodCall('add', [$matcher, $rule]);
        }
    }

    private function createRequestMatcher(ContainerBuilder $container, $path = null, $host = null, $methods = null)
    {
        $arguments = [$path, $host, $methods];
        $serialized = serialize($arguments);
        $id = 'fos_rest.request_matcher.'.md5($serialized).sha1($serialized);

        if (!$container->hasDefinition($id)) {
            // only add arguments that are necessary
            $container
                ->setDefinition($id, new DefinitionDecorator('fos_rest.request_matcher'))
                ->setArguments($arguments);
        }

        return new Reference($id);
    }

    private function loadParamFetcherListener(array $config, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if ($config['param_fetcher_listener']['enabled']) {
            $loader->load('param_fetcher_listener.xml');

            if (!empty($config['param_fetcher_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.param_fetcher_listener');
                $service->clearTag('kernel.event_listener');
            }

            if ($config['param_fetcher_listener']['force']) {
                $container->getDefinition('fos_rest.param_fetcher_listener')->replaceArgument(1, true);
            }
        }
    }

    private function loadBodyConverter(array $config, $validator, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if (empty($config['body_converter'])) {
            return;
        }

        if (!empty($config['body_converter']['enabled'])) {
            $loader->load('request_body_param_converter.xml');

            if (!empty($config['body_converter']['validation_errors_argument'])) {
                $container->getDefinition('fos_rest.converter.request_body')->replaceArgument(4, $config['body_converter']['validation_errors_argument']);
            }
        }

        if (!empty($config['body_converter']['validate'])) {
            $container->setAlias('fos_rest.validator', $validator);
        }
    }

    private function loadView(array $config, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if (!empty($config['view']['exception_wrapper_handler'])) {
            $container->setAlias('fos_rest.view.exception_wrapper_handler', $config['view']['exception_wrapper_handler']);
        }

        if (!empty($config['view']['jsonp_handler'])) {
            $handler = new DefinitionDecorator($config['service']['view_handler']);
            $handler->setPublic(true);

            $jsonpHandler = new Reference('fos_rest.view_handler.jsonp');
            $handler->addMethodCall('registerHandler', ['jsonp', [$jsonpHandler, 'createResponse']]);
            $container->setDefinition('fos_rest.view_handler', $handler);

            $container->getDefinition('fos_rest.view_handler.jsonp')->replaceArgument(0, $config['view']['jsonp_handler']['callback_param']);

            if (empty($config['view']['mime_types']['jsonp'])) {
                $config['view']['mime_types']['jsonp'] = $config['view']['jsonp_handler']['mime_type'];
            }
        }

        if ($config['view']['mime_types']['enabled']) {
            $loader->load('mime_type_listener.xml');

            if (!empty($config['mime_type_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.mime_type_listener');
                $service->clearTag('kernel.event_listener');
            }

            $container->getDefinition('fos_rest.mime_type_listener')->replaceArgument(0, $config['view']['mime_types']);
        }

        if ($config['view']['view_response_listener']['enabled']) {
            $loader->load('view_response_listener.xml');

            if (!empty($config['view_response_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.view_response_listener');
                $service->clearTag('kernel.event_listener');
            }

            $container->setParameter('fos_rest.view_response_listener.force_view', $config['view']['view_response_listener']['force']);
        }

        $formats = [];
        foreach ($config['view']['formats'] as $format => $enabled) {
            if ($enabled) {
                $formats[$format] = false;
            }
        }
        foreach ($config['view']['templating_formats'] as $format => $enabled) {
            if ($enabled) {
                $formats[$format] = true;
            }
        }

        $container->getDefinition('fos_rest.routing.loader.yaml_collection')->replaceArgument(3, $formats);
        $container->getDefinition('fos_rest.routing.loader.xml_collection')->replaceArgument(3, $formats);
        $container->getDefinition('fos_rest.routing.loader.reader.action')->replaceArgument(4, $formats);
        $container->getDefinition('fos_rest.view_handler.default')->replaceArgument(0, $formats);

        foreach ($config['view']['force_redirects'] as $format => $code) {
            if (true === $code) {
                $config['view']['force_redirects'][$format] = Response::HTTP_FOUND;
            }
        }

        if (!is_numeric($config['view']['failed_validation'])) {
            $config['view']['failed_validation'] = constant('\Symfony\Component\HttpFoundation\Response::'.$config['view']['failed_validation']);
        }

        $defaultViewHandler = $container->getDefinition('fos_rest.view_handler.default');
        $defaultViewHandler->replaceArgument(1, $config['view']['failed_validation']);

        if (!is_numeric($config['view']['empty_content'])) {
            $config['view']['empty_content'] = constant('\Symfony\Component\HttpFoundation\Response::'.$config['view']['empty_content']);
        }

        $defaultViewHandler->replaceArgument(2, $config['view']['empty_content']);
        $defaultViewHandler->replaceArgument(3, $config['view']['serialize_null']);
        $defaultViewHandler->replaceArgument(4, $config['view']['force_redirects']);
        $defaultViewHandler->replaceArgument(5, $config['view']['default_engine']);
    }

    private function loadException(array $config, XmlFileLoader $loader, ContainerBuilder $container)
    {
        if ($config['exception']['enabled']) {
            $loader->load('exception_listener.xml');

            if (!empty($config['exception']['service'])) {
                $service = $container->getDefinition('fos_rest.exception_listener');
                $service->clearTag('kernel.event_listener');
            }

            if ($config['exception']['exception_controller']) {
                $container->getDefinition('fos_rest.exception_listener')->replaceArgument(0, $config['exception']['exception_controller']);
            }

            if ($config['view']['mime_types']['enabled']) {
                $container->getDefinition('fos_rest.exception_format_negotiator')->replaceArgument(1, $config['view']['mime_types']['formats']);
            }
        }

        foreach ($config['exception']['codes'] as $exception => $code) {
            if (!is_numeric($code)) {
                $config['exception']['codes'][$exception] = constant("\Symfony\Component\HttpFoundation\Response::$code");
            }

            $this->testExceptionExists($exception);
        }

        foreach ($config['exception']['messages'] as $exception => $message) {
            $this->testExceptionExists($exception);
        }

        $container->setParameter('fos_rest.exception.codes', $config['exception']['codes']);
        $container->setParameter('fos_rest.exception.messages', $config['exception']['messages']);
    }

    private function loadSerializer(array $config, ContainerBuilder $container)
    {
        if (!empty($config['serializer']['version'])) {
            $container->getDefinition('fos_rest.converter.request_body')->replaceArgument(2, $config['serializer']['version']);
            $container->getDefinition('fos_rest.view_handler.default')->addMethodCall('setExclusionStrategyVersion', array($config['serializer']['version']));
        }

        if (!empty($config['serializer']['groups'])) {
            $container->getDefinition('fos_rest.converter.request_body')->replaceArgument(1, $config['serializer']['groups']);
            $container->getDefinition('fos_rest.view_handler.default')->addMethodCall('setExclusionStrategyGroups', array($config['serializer']['groups']));
        }

        $container->getDefinition('fos_rest.view_handler.default')->addMethodCall('setSerializeNullStrategy', array($config['serializer']['serialize_null']));
    }

    /**
     * Checks if an exception is loadable.
     *
     * @param string $exception Class to test
     *
     * @throws \InvalidArgumentException if the class was not found.
     */
    private function testExceptionExists($exception)
    {
        if (!is_subclass_of($exception, '\Exception') && !is_a($exception, '\Exception', true)) {
            throw new \InvalidArgumentException("FOSRestBundle exception mapper: Could not load class '$exception' or the class does not extend from '\Exception'. Most probably this is a configuration problem.");
        }
    }
}
