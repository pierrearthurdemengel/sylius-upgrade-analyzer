<?php

declare(strict_types=1);

// Exemples avant/apres des correctifs automatiques. Chaque paire est rejouee par
// FixerExamplesTest et sert a generer docs/fixer-examples.md
// (php .github/scripts/generate-fixer-examples.php).

return [
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiEndpointRestructureFixer::class,
        'file' => 'src/Api/AvatarClient.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

$url = \'/api/v2/admin/avatar-images/\';
',
        'target' => 'src/Api/AvatarClient.php',
        'before' => '<?php

$url = \'/api/v2/admin/avatar-images/\';
',
        'after' => '<?php

$url = \'/api/v2/admin/administrators/{id}/avatar-image\';
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiPlatformMigrationFixer::class,
        'file' => 'src/Entity/Product.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

use ApiPlatform\\Core\\Annotation\\ApiResource;

class Product
{
}
',
        'target' => 'src/Entity/Product.php',
        'before' => '<?php

use ApiPlatform\\Core\\Annotation\\ApiResource;

class Product
{
}
',
        'after' => '<?php

use ApiPlatform\\Metadata\\ApiResource;

class Product
{
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiQueryExtensionSignatureFixer::class,
        'file' => 'src/ApiExtension/ProductExtension.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

namespace App\\ApiExtension;

class ProductExtension
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null): void
    {
    }
}
',
        'target' => 'src/ApiExtension/ProductExtension.php',
        'before' => '<?php

namespace App\\ApiExtension;

class ProductExtension
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null): void
    {
    }
}
',
        'after' => '<?php

namespace App\\ApiExtension;

use ApiPlatform\\Metadata\\Operation;

class ProductExtension
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null): void
    {
    }
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ApiSerializationGroupFixer::class,
        'file' => 'src/Entity/Product.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

#[Groups([\'admin:read\', \'admin:write\'])]
class Product {
}
',
        'target' => 'src/Entity/Product.php',
        'before' => '<?php

#[Groups([\'admin:read\', \'admin:write\'])]
class Product {
}
',
        'after' => '<?php

#[Groups([\'sylius:admin:read\', \'sylius:admin:write\'])]
class Product {
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\BundleConfigFixer::class,
        'file' => 'config/bundles.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

return [
    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => [\'all\' => true],
    winzou\\Bundle\\StateMachineBundle\\winzouStateMachineBundle::class => [\'all\' => true],
];
',
        'target' => 'config/bundles.php',
        'before' => '<?php

return [
    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => [\'all\' => true],
    winzou\\Bundle\\StateMachineBundle\\winzouStateMachineBundle::class => [\'all\' => true],
];
',
        'after' => '<?php

return [
    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => [\'all\' => true],
    Sylius\\TwigHooks\\SyliusTwigHooksBundle::class => [\'all\' => true],
    Symfony\\UX\\TwigComponent\\TwigComponentBundle::class => [\'all\' => true],
    Symfony\\UX\\StimulusBundle\\StimulusBundle::class => [\'all\' => true],
    Symfony\\UX\\LiveComponent\\LiveComponentBundle::class => [\'all\' => true],
    Symfony\\UX\\Autocomplete\\AutocompleteBundle::class => [\'all\' => true],
];
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\CalendarToClockFixer::class,
        'file' => 'src/Service/DateService.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

use Sylius\\Calendar\\Provider\\DateTimeProviderInterface;

class DateService
{
}
',
        'target' => 'src/Service/DateService.php',
        'before' => '<?php

use Sylius\\Calendar\\Provider\\DateTimeProviderInterface;

class DateService
{
}
',
        'after' => '<?php

use Symfony\\Component\\Clock\\ClockInterface;

class DateService
{
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ClassMoveFixer::class,
        'file' => 'src/Controller/ContactController.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

use Sylius\\Bundle\\ShopBundle\\EmailManager\\ContactEmailManager;

class ContactController
{
}
',
        'target' => 'src/Controller/ContactController.php',
        'before' => '<?php

use Sylius\\Bundle\\ShopBundle\\EmailManager\\ContactEmailManager;

class ContactController
{
}
',
        'after' => '<?php

use Sylius\\Bundle\\CoreBundle\\Mailer\\ContactEmailManager;

class ContactController
{
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\CommandHandlerFixer::class,
        'file' => 'src/Controller/OrderController.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

namespace App\\Controller;

use Sylius\\Bundle\\CoreBundle\\Message\\ResendOrderConfirmationEmail;
use Symfony\\Component\\Messenger\\MessageBusInterface;

final class OrderController
{
    public function resend(MessageBusInterface $bus, string $orderToken): void
    {
        $bus->dispatch(new ResendOrderConfirmationEmail($orderToken));
    }
}
',
        'target' => 'src/Controller/OrderController.php',
        'before' => '<?php

namespace App\\Controller;

use Sylius\\Bundle\\CoreBundle\\Message\\ResendOrderConfirmationEmail;
use Symfony\\Component\\Messenger\\MessageBusInterface;

final class OrderController
{
    public function resend(MessageBusInterface $bus, string $orderToken): void
    {
        $bus->dispatch(new ResendOrderConfirmationEmail($orderToken));
    }
}
',
        'after' => '<?php

namespace App\\Controller;

use Sylius\\Bundle\\CoreBundle\\Command\\ResendOrderConfirmationEmail;
use Symfony\\Component\\Messenger\\MessageBusInterface;

final class OrderController
{
    public function resend(MessageBusInterface $bus, string $orderToken): void
    {
        $bus->dispatch(new ResendOrderConfirmationEmail($orderToken));
    }
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ConstructorSignatureFixer::class,
        'file' => 'src/Addressing/CustomZoneMatcher.php',
        'line' => 7,
        'message' => null,
        'content' => '<?php

namespace App\\Addressing;

use Sylius\\Component\\Addressing\\Matcher\\ZoneMatcher;

class CustomZoneMatcher extends ZoneMatcher
{
    public function __construct(
        private readonly SomeService $service,
    ) {
        parent::__construct();
    }
}',
        'target' => 'src/Addressing/CustomZoneMatcher.php',
        'before' => '<?php

namespace App\\Addressing;

use Sylius\\Component\\Addressing\\Matcher\\ZoneMatcher;

class CustomZoneMatcher extends ZoneMatcher
{
    public function __construct(
        private readonly SomeService $service,
    ) {
        parent::__construct();
    }
}',
        'after' => '<?php

namespace App\\Addressing;

use Sylius\\Component\\Addressing\\Matcher\\ZoneMatcher;

class CustomZoneMatcher extends ZoneMatcher
{
    // TODO: signature du constructeur parent ZoneMatcher modifiee dans Sylius 2.0 — adapter les arguments
    public function __construct(
        private readonly SomeService $service,
    ) {
        parent::__construct();
    }
}',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\DeprecatedBundlePackageFixer::class,
        'file' => 'composer.json',
        'line' => null,
        'message' => null,
        'content' => '{
    "require": {
        "sylius/sylius": "^1.12",
        "friendsofsymfony/rest-bundle": "^3.0"
    }
}
',
        'target' => 'composer.json',
        'before' => '{
    "require": {
        "sylius/sylius": "^1.12",
        "friendsofsymfony/rest-bundle": "^3.0"
    }
}
',
        'after' => '{
    "require": {
        "sylius/sylius": "^1.12"
    }
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\DeprecatedEmailManagerFixer::class,
        'file' => 'src/Controller/OrderController.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

use Sylius\\Bundle\\ShopBundle\\EmailManager\\OrderEmailManagerInterface;

class OrderController
{
    public function __construct(private OrderEmailManagerInterface $emailManager)
    {
    }
}
',
        'target' => 'src/Controller/OrderController.php',
        'before' => '<?php

use Sylius\\Bundle\\ShopBundle\\EmailManager\\OrderEmailManagerInterface;

class OrderController
{
    public function __construct(private OrderEmailManagerInterface $emailManager)
    {
    }
}
',
        'after' => '<?php

use Sylius\\Bundle\\CoreBundle\\Mailer\\OrderEmailManagerInterface;

class OrderController
{
    public function __construct(private OrderEmailManagerInterface $emailManager)
    {
    }
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\DoctrineXmlMappingFixer::class,
        'file' => 'config/doctrine/Product.orm.xml',
        'line' => null,
        'message' => null,
        'content' => '<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping">
    <entity name="App\\Entity\\Product" table="sylius_product">
        <field name="name" type="string" length="255"/>
    </entity>
</doctrine-mapping>',
        'target' => 'config/doctrine/Product.orm.xml',
        'before' => '<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping">
    <entity name="App\\Entity\\Product" table="sylius_product">
        <field name="name" type="string" length="255"/>
    </entity>
</doctrine-mapping>',
        'after' => '<?xml version="1.0" encoding="UTF-8"?>
<!-- TODO: convertir en attributs PHP (#[ORM\\Entity], #[ORM\\Column], etc.) -->
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping">
    <entity name="App\\Entity\\Product" table="sylius_product">
        <field name="name" type="string" length="255"/>
    </entity>
</doctrine-mapping>',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\GridFilterEntityFixer::class,
        'file' => 'config/grids/order.yaml',
        'line' => null,
        'message' => null,
        'content' => 'sylius_grid:
    grids:
        app_order:
            filters:
                customer:
                    type: entities
                    options:
                        fields: [customer]
',
        'target' => 'config/grids/order.yaml',
        'before' => 'sylius_grid:
    grids:
        app_order:
            filters:
                customer:
                    type: entities
                    options:
                        fields: [customer]
',
        'after' => 'sylius_grid:
    grids:
        app_order:
            filters:
                customer:
                    type: entity
                    options:
                        fields: [customer]
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\LiipImagineConfigFixer::class,
        'file' => 'config/packages/liip_imagine.yaml',
        'line' => null,
        'message' => null,
        'content' => 'liip_imagine:
    resolvers:
        default:
            web_path: ~
',
        'target' => 'config/packages/liip_imagine.yaml',
        'before' => 'liip_imagine:
    resolvers:
        default:
            web_path: ~
',
        'after' => 'liip_imagine:
    resolvers:
        sylius_image:
            web_path: ~
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\MessageBusFixer::class,
        'file' => 'config/packages/messenger.yaml',
        'line' => null,
        'message' => null,
        'content' => 'services:
    App\\MessageHandler\\:
        resource: \'../src/MessageHandler/\'
        tags:
            - name: messenger.message_handler
              bus: sylius_default.bus
',
        'target' => 'config/packages/messenger.yaml',
        'before' => 'services:
    App\\MessageHandler\\:
        resource: \'../src/MessageHandler/\'
        tags:
            - name: messenger.message_handler
              bus: sylius_default.bus
',
        'after' => 'services:
    App\\MessageHandler\\:
        resource: \'../src/MessageHandler/\'
        tags:
            - name: messenger.message_handler
              bus: sylius.command_bus
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\MultiStoreChannelFixer::class,
        'file' => 'src/Context/ChannelContext.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

class ChannelContext
{
    public function getChannel(): Channel
    {
        return $this->channelRepository->findOneByHostname($hostname);
    }
}',
        'target' => 'src/Context/ChannelContext.php',
        'before' => '<?php

class ChannelContext
{
    public function getChannel(): Channel
    {
        return $this->channelRepository->findOneByHostname($hostname);
    }
}',
        'after' => '<?php

class ChannelContext
{
    public function getChannel(): Channel
    {
        return $this->channelRepository->findOneEnabledByHostname($hostname);
    }
}',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\PaymentRequestEnvFixer::class,
        'file' => '.env',
        'line' => null,
        'message' => null,
        'content' => 'APP_ENV=dev
APP_SECRET=abc123
',
        'target' => '.env',
        'before' => 'APP_ENV=dev
APP_SECRET=abc123
',
        'after' => 'APP_ENV=dev
APP_SECRET=abc123

###> sylius/payment-request ###
SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync://
SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN=sync://
###< sylius/payment-request ###
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\PromotionRuleCheckerFixer::class,
        'file' => 'src/Promotion/CustomChecker.php',
        'line' => null,
        'message' => null,
        'content' => '<?php

use Sylius\\Component\\Promotion\\Checker\\Rule\\CartQuantityRuleChecker;

class CustomChecker extends CartQuantityRuleChecker
{
}
',
        'target' => 'src/Promotion/CustomChecker.php',
        'before' => '<?php

use Sylius\\Component\\Promotion\\Checker\\Rule\\CartQuantityRuleChecker;

class CustomChecker extends CartQuantityRuleChecker
{
}
',
        'after' => '<?php

use Sylius\\Component\\Core\\Promotion\\Checker\\Rule\\CartQuantityRuleChecker;

class CustomChecker extends CartQuantityRuleChecker
{
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedClassFixer::class,
        'file' => 'src/Service/BasketService.php',
        'line' => 5,
        'message' => null,
        'content' => '<?php

declare(strict_types=1);

use Sylius\\Bundle\\CoreBundle\\Templating\\Helper\\BasketHelper;

class BasketService
{
}
',
        'target' => 'src/Service/BasketService.php',
        'before' => '<?php

declare(strict_types=1);

use Sylius\\Bundle\\CoreBundle\\Templating\\Helper\\BasketHelper;

class BasketService
{
}
',
        'after' => '<?php

declare(strict_types=1);

// TODO: classe supprimee dans Sylius 2.0 — use Sylius\\Bundle\\CoreBundle\\Templating\\Helper\\BasketHelper;

class BasketService
{
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedConfigKeyFixer::class,
        'file' => 'config/packages/sylius_resource.yaml',
        'line' => 2,
        'message' => null,
        'content' => 'sylius_resource:
    autoconfigure_with_attributes: true
    other_key: value
',
        'target' => 'config/packages/sylius_resource.yaml',
        'before' => 'sylius_resource:
    autoconfigure_with_attributes: true
    other_key: value
',
        'after' => 'sylius_resource:
# TODO: cle supprimee dans Sylius 2.0 — autoconfigure_with_attributes: true
    other_key: value
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedPaymentGatewayFixer::class,
        'file' => 'config/packages/payum.yaml',
        'line' => null,
        'message' => null,
        'content' => 'gateways:
    stripe_checkout:
        factory_name: stripe_checkout
',
        'target' => 'config/packages/payum.yaml',
        'before' => 'gateways:
    stripe_checkout:
        factory_name: stripe_checkout
',
        'after' => 'gateways:
    # TODO: passerelle supprimee du coeur dans Sylius 2.0, a remplacer par un plugin de paiement
    # stripe_checkout:
    #     factory_name: stripe_checkout
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RemovedRouteFixer::class,
        'file' => 'src/Controller/ShopController.php',
        'line' => 5,
        'message' => null,
        'content' => '<?php

class ShopController
{
    $route = \'sylius_shop_cart_summary\';
}
',
        'target' => 'src/Controller/ShopController.php',
        'before' => '<?php

class ShopController
{
    $route = \'sylius_shop_cart_summary\';
}
',
        'after' => '<?php

class ShopController
{
// TODO: route supprimee dans Sylius 2.0 — $route = \'sylius_shop_cart_summary\';
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RenamedServiceIdFixer::class,
        'file' => 'config/services.yaml',
        'line' => null,
        'message' => null,
        'content' => 'services:
    app.service:
        arguments:
            - \'@sylius.twig.extension.sort_by\'
',
        'target' => 'config/services.yaml',
        'before' => 'services:
    app.service:
        arguments:
            - \'@sylius.twig.extension.sort_by\'
',
        'after' => 'services:
    app.service:
        arguments:
            - \'@sylius_twig_extra.twig.extension.sort_by\'
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ResourceBundleFixer::class,
        'file' => 'config/packages/sylius_resource.yaml',
        'line' => null,
        'message' => null,
        'content' => 'sylius_resource:
    resources:
        app.review:
            driver: doctrine/mongodb-odm
            classes:
                model: App\\Document\\Review
',
        'target' => 'config/packages/sylius_resource.yaml',
        'before' => 'sylius_resource:
    resources:
        app.review:
            driver: doctrine/mongodb-odm
            classes:
                model: App\\Document\\Review
',
        'after' => 'sylius_resource:
    resources:
        app.review:
            # TODO: MongoDB supprime — driver: doctrine/mongodb-odm
            classes:
                model: App\\Document\\Review
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\RoutingImportFixer::class,
        'file' => 'config/routes/sylius_shop.yaml',
        'line' => null,
        'message' => null,
        'content' => 'imports:
    - { resource: \'@SyliusShopBundle/Resources/config/routing/payum.yml\' }
',
        'target' => 'config/routes/sylius_shop.yaml',
        'before' => 'imports:
    - { resource: \'@SyliusShopBundle/Resources/config/routing/payum.yml\' }
',
        'after' => 'imports:
    - { resource: \'@SyliusPayumBundle/Resources/config/routing/integrations/sylius_shop.yaml\' }
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\SecurityConfigFixer::class,
        'file' => 'config/packages/security.yaml',
        'line' => null,
        'message' => 'Configuration "encoders:" depreciee dans security.yaml',
        'content' => 'security:
    encoders:
        App\\Entity\\User:
            algorithm: bcrypt
',
        'target' => 'config/packages/security.yaml',
        'before' => 'security:
    encoders:
        App\\Entity\\User:
            algorithm: bcrypt
',
        'after' => 'security:
    password_hashers:
        App\\Entity\\User: auto
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\SecurityFirewallFixer::class,
        'file' => 'config/packages/security.yaml',
        'line' => null,
        'message' => null,
        'content' => 'security:
    firewalls:
        new_api_admin_user:
            pattern: ^/api/v2/admin
        new_api_shop_user:
            pattern: ^/api/v2/shop
',
        'target' => 'config/packages/security.yaml',
        'before' => 'security:
    firewalls:
        new_api_admin_user:
            pattern: ^/api/v2/admin
        new_api_shop_user:
            pattern: ^/api/v2/shop
',
        'after' => 'security:
    firewalls:
        api_admin:
            pattern: ^/api/v2/admin
        api_shop:
            pattern: ^/api/v2/shop
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ServiceDecoratorFixer::class,
        'file' => 'config/services.yaml',
        'line' => null,
        'message' => null,
        'content' => 'services:
    App\\Controller\\DashboardController:
        decorates: sylius.controller.admin.dashboard
        arguments: [\'@.inner\']
',
        'target' => 'config/services.yaml',
        'before' => 'services:
    App\\Controller\\DashboardController:
        decorates: sylius.controller.admin.dashboard
        arguments: [\'@.inner\']
',
        'after' => 'services:
    App\\Controller\\DashboardController:
        decorates: sylius_admin.controller.dashboard
        arguments: [\'@.inner\']
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\ShippingCalculatorFixer::class,
        'file' => 'config/services.yaml',
        'line' => null,
        'message' => null,
        'content' => 'services:
    App\\Shipping\\WeightCalculator:
        arguments: [\'@sylius.shipping_calculator.flat_rate\']
        tags:
            - { name: sylius.shipping_calculator, calculator: weight, label: Poids }
',
        'target' => 'config/services.yaml',
        'before' => 'services:
    App\\Shipping\\WeightCalculator:
        arguments: [\'@sylius.shipping_calculator.flat_rate\']
        tags:
            - { name: sylius.shipping_calculator, calculator: weight, label: Poids }
',
        'after' => 'services:
    App\\Shipping\\WeightCalculator:
        arguments: [\'@sylius.calculator.shipping.flat_rate\']
        tags:
            - { name: sylius.shipping_calculator, calculator: weight, label: Poids }
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\OrderProcessorAutoconfigurationFixer::class,
        'file' => 'src/OrderProcessing/GiftWrappingProcessor.php',
        'line' => 8,
        'message' => 'App\\OrderProcessing\\GiftWrappingProcessor ne sera plus enregistre : OrderProcessorInterface sans #[AsOrderProcessor] ni tag sylius.order_processor',
        'content' => '<?php

namespace App\\OrderProcessing;

use Sylius\\Component\\Order\\Model\\OrderInterface;
use Sylius\\Component\\Order\\Processor\\OrderProcessorInterface;

final class GiftWrappingProcessor implements OrderProcessorInterface
{
    public function process(OrderInterface $order): void
    {
    }
}
',
        'target' => 'src/OrderProcessing/GiftWrappingProcessor.php',
        'before' => '<?php

namespace App\\OrderProcessing;

use Sylius\\Component\\Order\\Model\\OrderInterface;
use Sylius\\Component\\Order\\Processor\\OrderProcessorInterface;

final class GiftWrappingProcessor implements OrderProcessorInterface
{
    public function process(OrderInterface $order): void
    {
    }
}
',
        'after' => '<?php

namespace App\\OrderProcessing;

use Sylius\\Component\\Order\\Model\\OrderInterface;
use Sylius\\Component\\Order\\Processor\\OrderProcessorInterface;
use Sylius\\Bundle\\OrderBundle\\Attribute\\AsOrderProcessor;

#[AsOrderProcessor]
final class GiftWrappingProcessor implements OrderProcessorInterface
{
    public function process(OrderInterface $order): void
    {
    }
}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\SonataBlockEventFixer::class,
        'file' => 'templates/layout.html.twig',
        'line' => null,
        'message' => null,
        'content' => '{% block body %}
{{ sonata_block_render_event(\'sylius.shop.layout.header\') }}
{% endblock %}
',
        'target' => 'templates/layout.html.twig',
        'before' => '{% block body %}
{{ sonata_block_render_event(\'sylius.shop.layout.header\') }}
{% endblock %}
',
        'after' => '{% block body %}
{{ hook(\'sylius.shop.layout.header\') }}
{% endblock %}
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\SwiftMailerFixer::class,
        'file' => 'config/packages/swiftmailer.yaml',
        'line' => null,
        'message' => null,
        'content' => 'swiftmailer:
    url: \'%env(MAILER_URL)%\'
    spool: { type: memory }
',
        'target' => 'config/packages/swiftmailer.yaml',
        'before' => 'swiftmailer:
    url: \'%env(MAILER_URL)%\'
    spool: { type: memory }
',
        'after' => 'framework:
    mailer:
        dsn: \'%env(MAILER_DSN)%\'

# TODO: ancienne configuration SwiftMailer, a reporter dans framework.mailer puis supprimer
# swiftmailer:
#     url: \'%env(MAILER_URL)%\'
#     spool: { type: memory }
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\TwigHookFixer::class,
        'file' => 'templates/bundles/SyliusShopBundle/Product/show.html.twig',
        'line' => null,
        'message' => null,
        'content' => '{% extends \'@!SyliusShop/Product/show.html.twig\' %}

{% block content %}
    <div class="ui segment">{{ product.name }}</div>
{% endblock %}
',
        'target' => 'config/packages/sylius_twig_hooks.yaml',
        'before' => '',
        'after' => 'sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content:
            app.sylius_shop_bundle_product_show:
                template: \'hooks/SyliusShopBundle_Product_show.html.twig\'
                # priority: 0
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\UseWebpackConfigFixer::class,
        'file' => 'config/packages/sylius_ui.yaml',
        'line' => null,
        'message' => null,
        'content' => 'sylius_ui:
    events:
        sylius.shop.layout:
            use_webpack: true
            blocks:
                header: ~
',
        'target' => 'config/packages/sylius_ui.yaml',
        'before' => 'sylius_ui:
    events:
        sylius.shop.layout:
            use_webpack: true
            blocks:
                header: ~
',
        'after' => 'sylius_ui:
    events:
        sylius.shop.layout:
            blocks:
                header: ~
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\UserEncoderFixer::class,
        'file' => 'config/packages/security.yaml',
        'line' => null,
        'message' => null,
        'content' => 'security:
    encoders:
        App\\Entity\\User:
            algorithm: bcrypt
',
        'target' => 'config/packages/security.yaml',
        'before' => 'security:
    encoders:
        App\\Entity\\User:
            algorithm: bcrypt
',
        'after' => 'security:
    password_hashers:
        App\\Entity\\User:
            algorithm: auto
',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\UserModelFieldFixer::class,
        'file' => 'src/Entity/User/AdminUser.php',
        'line' => 5,
        'message' => null,
        'content' => '<?php

class User
{
    private bool $locked = false;

    public function isLocked(): bool
    {
        return $this->locked;
    }
}',
        'target' => 'src/Entity/User/AdminUser.php',
        'before' => '<?php

class User
{
    private bool $locked = false;

    public function isLocked(): bool
    {
        return $this->locked;
    }
}',
        'after' => '<?php

class User
{
    // TODO: propriete supprimee dans Sylius 2.0 — private bool $locked = false;

    public function isLocked(): bool
    {
        return $this->locked;
    }
}',
    ],
    [
        'fixer' => \PierreArthur\SyliusUpgradeAnalyzer\AutoFix\WorkflowMigrationFixer::class,
        'file' => 'config/packages/winzou_state_machine.yaml',
        'line' => null,
        'message' => 'Machine a etats winzou "app_subscription" a migrer',
        'content' => 'winzou_state_machine:
    app_subscription:
        class: App\\Entity\\Subscription
        property_path: state
        graph: app_subscription
        states: [new, active, cancelled]
        transitions:
            activate:
                from: [new]
                to: active
            cancel:
                from: [new, active]
                to: cancelled
',
        'target' => 'config/packages/workflow.yaml',
        'before' => '',
        'after' => 'framework:
    workflows:
        app_subscription:
            type: state_machine
            audit_trail:
                enabled: true
            marking_store:
                type: method
                property: state
            supports:
                - App\\Entity\\Subscription
            places:
                - new
                - active
                - cancelled
            transitions:
                activate:
                    from:
                        - new
                    to: active
                cancel:
                    from:
                        - new
                        - active
                    to: cancelled
',
    ],
];
