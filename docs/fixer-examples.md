# Auto-Fix Examples

Real input and output of each auto-fixer. This file is generated from `tests/Fixtures/fixer-examples.php`: `FixerExamplesTest` replays every example against the fixer and fails if the output or this document drifts.

See [fixers.md](fixers.md) for what each confidence level means and how fixes are applied.

## High confidence

### API Endpoint Restructure Fixer

`src/Api/AvatarClient.php`

Before:

```php
<?php

$url = '/api/v2/admin/avatar-images/';
```

After:

```php
<?php

$url = '/api/v2/admin/administrators/{id}/avatar-image';
```

### Bundle Configuration Fixer

`config/bundles.php`

Before:

```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    winzou\Bundle\StateMachineBundle\winzouStateMachineBundle::class => ['all' => true],
];
```

After:

```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Sylius\TwigHooks\SyliusTwigHooksBundle::class => ['all' => true],
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    Symfony\UX\LiveComponent\LiveComponentBundle::class => ['all' => true],
    Symfony\UX\Autocomplete\AutocompleteBundle::class => ['all' => true],
];
```

### Calendar to Clock Fixer

`src/Service/DateService.php`

Before:

```php
<?php

use Sylius\Calendar\Provider\DateTimeProviderInterface;

class DateService
{
}
```

After:

```php
<?php

use Symfony\Component\Clock\ClockInterface;

class DateService
{
}
```

### Class Move Fixer

`src/Controller/ContactController.php`

Before:

```php
<?php

use Sylius\Bundle\ShopBundle\EmailManager\ContactEmailManager;

class ContactController
{
}
```

After:

```php
<?php

use Sylius\Bundle\CoreBundle\Mailer\ContactEmailManager;

class ContactController
{
}
```

### Command Handler Rename Fixer

`src/Controller/OrderController.php`

Before:

```php
<?php

namespace App\Controller;

use Sylius\Bundle\CoreBundle\Message\ResendOrderConfirmationEmail;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrderController
{
    public function resend(MessageBusInterface $bus, string $orderToken): void
    {
        $bus->dispatch(new ResendOrderConfirmationEmail($orderToken));
    }
}
```

After:

```php
<?php

namespace App\Controller;

use Sylius\Bundle\CoreBundle\Command\ResendOrderConfirmationEmail;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrderController
{
    public function resend(MessageBusInterface $bus, string $orderToken): void
    {
        $bus->dispatch(new ResendOrderConfirmationEmail($orderToken));
    }
}
```

### Deprecated Bundle Package Fixer

`composer.json`

Before:

```json
{
    "require": {
        "sylius/sylius": "^1.12",
        "friendsofsymfony/rest-bundle": "^3.0"
    }
}
```

After:

```json
{
    "require": {
        "sylius/sylius": "^1.12"
    }
}
```

### Grid Filter Entity Fixer

`config/grids/order.yaml`

Before:

```yaml
sylius_grid:
    grids:
        app_order:
            filters:
                customer:
                    type: entities
                    options:
                        fields: [customer]
```

After:

```yaml
sylius_grid:
    grids:
        app_order:
            filters:
                customer:
                    type: entity
                    options:
                        fields: [customer]
```

### Liip Imagine Config Fixer

`config/packages/liip_imagine.yaml`

Before:

```yaml
liip_imagine:
    resolvers:
        default:
            web_path: ~
```

After:

```yaml
liip_imagine:
    resolvers:
        sylius_image:
            web_path: ~
```

### Message Bus Fixer

`config/packages/messenger.yaml`

Before:

```yaml
services:
    App\MessageHandler\:
        resource: '../src/MessageHandler/'
        tags:
            - name: messenger.message_handler
              bus: sylius_default.bus
```

After:

```yaml
services:
    App\MessageHandler\:
        resource: '../src/MessageHandler/'
        tags:
            - name: messenger.message_handler
              bus: sylius.command_bus
```

### Multi-Store Channel Fixer

`src/Context/ChannelContext.php`

Before:

```php
<?php

class ChannelContext
{
    public function getChannel(): Channel
    {
        return $this->channelRepository->findOneByHostname($hostname);
    }
}
```

After:

```php
<?php

class ChannelContext
{
    public function getChannel(): Channel
    {
        return $this->channelRepository->findOneEnabledByHostname($hostname);
    }
}
```

### Payment Request Env Fixer

`.env`

Before:

```text
APP_ENV=dev
APP_SECRET=abc123
```

After:

```text
APP_ENV=dev
APP_SECRET=abc123

###> sylius/payment-request ###
SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync://
SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN=sync://
###< sylius/payment-request ###
```

### Removed Config Key Fixer

`config/packages/sylius_resource.yaml`

Before:

```yaml
sylius_resource:
    autoconfigure_with_attributes: true
    other_key: value
```

After:

```yaml
sylius_resource:
# TODO: cle supprimee dans Sylius 2.0 — autoconfigure_with_attributes: true
    other_key: value
```

### Renamed Service ID Fixer

`config/services.yaml`

Before:

```yaml
services:
    app.service:
        arguments:
            - '@sylius.twig.extension.sort_by'
```

After:

```yaml
services:
    app.service:
        arguments:
            - '@sylius_twig_extra.twig.extension.sort_by'
```

### Routing Import Fixer

`config/routes/sylius_shop.yaml`

Before:

```yaml
imports:
    - { resource: '@SyliusShopBundle/Resources/config/routing/payum.yml' }
```

After:

```yaml
imports:
    - { resource: '@SyliusPayumBundle/Resources/config/routing/integrations/sylius_shop.yaml' }
```

### Security Config Fixer

`config/packages/security.yaml`

Before:

```yaml
security:
    encoders:
        App\Entity\User:
            algorithm: bcrypt
```

After:

```yaml
security:
    password_hashers:
        App\Entity\User: auto
```

### Security Firewall Fixer

`config/packages/security.yaml`

Before:

```yaml
security:
    firewalls:
        new_api_admin_user:
            pattern: ^/api/v2/admin
        new_api_shop_user:
            pattern: ^/api/v2/shop
```

After:

```yaml
security:
    firewalls:
        api_admin:
            pattern: ^/api/v2/admin
        api_shop:
            pattern: ^/api/v2/shop
```

### Shipping Calculator Fixer

`config/services.yaml`

Before:

```yaml
services:
    App\Shipping\WeightCalculator:
        arguments: ['@sylius.shipping_calculator.flat_rate']
        tags:
            - { name: sylius.shipping_calculator, calculator: weight, label: Poids }
```

After:

```yaml
services:
    App\Shipping\WeightCalculator:
        arguments: ['@sylius.calculator.shipping.flat_rate']
        tags:
            - { name: sylius.shipping_calculator, calculator: weight, label: Poids }
```

### Order Processor Autoconfiguration Fixer

`src/OrderProcessing/GiftWrappingProcessor.php`

Before:

```php
<?php

namespace App\OrderProcessing;

use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

final class GiftWrappingProcessor implements OrderProcessorInterface
{
    public function process(OrderInterface $order): void
    {
    }
}
```

After:

```php
<?php

namespace App\OrderProcessing;

use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Bundle\OrderBundle\Attribute\AsOrderProcessor;

#[AsOrderProcessor]
final class GiftWrappingProcessor implements OrderProcessorInterface
{
    public function process(OrderInterface $order): void
    {
    }
}
```

### Use Webpack Config Fixer

`config/packages/sylius_ui.yaml`

Before:

```yaml
sylius_ui:
    events:
        sylius.shop.layout:
            use_webpack: true
            blocks:
                header: ~
```

After:

```yaml
sylius_ui:
    events:
        sylius.shop.layout:
            blocks:
                header: ~
```

### User Encoder Fixer

`config/packages/security.yaml`

Before:

```yaml
security:
    encoders:
        App\Entity\User:
            algorithm: bcrypt
```

After:

```yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: auto
```

## Medium confidence

### API Platform Migration Fixer

`src/Entity/Product.php`

Before:

```php
<?php

use ApiPlatform\Core\Annotation\ApiResource;

class Product
{
}
```

After:

```php
<?php

use ApiPlatform\Metadata\ApiResource;

class Product
{
}
```

### API Query Extension Signature Fixer

`src/ApiExtension/ProductExtension.php`

Before:

```php
<?php

namespace App\ApiExtension;

class ProductExtension
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null): void
    {
    }
}
```

After:

```php
<?php

namespace App\ApiExtension;

use ApiPlatform\Metadata\Operation;

class ProductExtension
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null): void
    {
    }
}
```

### API Serialization Group Fixer

`src/Entity/Product.php`

Before:

```php
<?php

#[Groups(['admin:read', 'admin:write'])]
class Product {
}
```

After:

```php
<?php

#[Groups(['sylius:admin:read', 'sylius:admin:write'])]
class Product {
}
```

### Constructor Signature Fixer

`src/Addressing/CustomZoneMatcher.php`

Before:

```php
<?php

namespace App\Addressing;

use Sylius\Component\Addressing\Matcher\ZoneMatcher;

class CustomZoneMatcher extends ZoneMatcher
{
    public function __construct(
        private readonly SomeService $service,
    ) {
        parent::__construct();
    }
}
```

After:

```php
<?php

namespace App\Addressing;

use Sylius\Component\Addressing\Matcher\ZoneMatcher;

class CustomZoneMatcher extends ZoneMatcher
{
    // TODO: signature du constructeur parent ZoneMatcher modifiee dans Sylius 2.0 — adapter les arguments
    public function __construct(
        private readonly SomeService $service,
    ) {
        parent::__construct();
    }
}
```

### Deprecated Email Manager Fixer

`src/Controller/OrderController.php`

Before:

```php
<?php

use Sylius\Bundle\ShopBundle\EmailManager\OrderEmailManagerInterface;

class OrderController
{
    public function __construct(private OrderEmailManagerInterface $emailManager)
    {
    }
}
```

After:

```php
<?php

use Sylius\Bundle\CoreBundle\Mailer\OrderEmailManagerInterface;

class OrderController
{
    public function __construct(private OrderEmailManagerInterface $emailManager)
    {
    }
}
```

### Promotion Rule Checker Fixer

`src/Promotion/CustomChecker.php`

Before:

```php
<?php

use Sylius\Component\Promotion\Checker\Rule\CartQuantityRuleChecker;

class CustomChecker extends CartQuantityRuleChecker
{
}
```

After:

```php
<?php

use Sylius\Component\Core\Promotion\Checker\Rule\CartQuantityRuleChecker;

class CustomChecker extends CartQuantityRuleChecker
{
}
```

### Removed Class Fixer

`src/Service/BasketService.php`

Before:

```php
<?php

declare(strict_types=1);

use Sylius\Bundle\CoreBundle\Templating\Helper\BasketHelper;

class BasketService
{
}
```

After:

```php
<?php

declare(strict_types=1);

// TODO: classe supprimee dans Sylius 2.0 — use Sylius\Bundle\CoreBundle\Templating\Helper\BasketHelper;

class BasketService
{
}
```

### Removed Payment Gateway Fixer

`config/packages/payum.yaml`

Before:

```yaml
gateways:
    stripe_checkout:
        factory_name: stripe_checkout
```

After:

```yaml
gateways:
    # TODO: passerelle supprimee du coeur dans Sylius 2.0, a remplacer par un plugin de paiement
    # stripe_checkout:
    #     factory_name: stripe_checkout
```

### Removed Route Fixer

`src/Controller/ShopController.php`

Before:

```php
<?php

class ShopController
{
    $route = 'sylius_shop_cart_summary';
}
```

After:

```php
<?php

class ShopController
{
// TODO: route supprimee dans Sylius 2.0 — $route = 'sylius_shop_cart_summary';
}
```

### Resource Bundle Fixer

`config/packages/sylius_resource.yaml`

Before:

```yaml
sylius_resource:
    resources:
        app.review:
            driver: doctrine/mongodb-odm
            classes:
                model: App\Document\Review
```

After:

```yaml
sylius_resource:
    resources:
        app.review:
            # TODO: MongoDB supprime — driver: doctrine/mongodb-odm
            classes:
                model: App\Document\Review
```

### Service Decorator Fixer

`config/services.yaml`

Before:

```yaml
services:
    App\Controller\DashboardController:
        decorates: sylius.controller.admin.dashboard
        arguments: ['@.inner']
```

After:

```yaml
services:
    App\Controller\DashboardController:
        decorates: sylius_admin.controller.dashboard
        arguments: ['@.inner']
```

### Sonata Block Event Fixer

`templates/layout.html.twig`

Before:

```twig
{% block body %}
{{ sonata_block_render_event('sylius.shop.layout.header') }}
{% endblock %}
```

After:

```twig
{% block body %}
{{ hook('sylius.shop.layout.header') }}
{% endblock %}
```

### SwiftMailer Fixer

`config/packages/swiftmailer.yaml`

Before:

```yaml
swiftmailer:
    url: '%env(MAILER_URL)%'
    spool: { type: memory }
```

After:

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'

# TODO: ancienne configuration SwiftMailer, a reporter dans framework.mailer puis supprimer
# swiftmailer:
#     url: '%env(MAILER_URL)%'
#     spool: { type: memory }
```

### Twig Hook Fixer

`config/packages/sylius_twig_hooks.yaml` (issue reported on `templates/bundles/SyliusShopBundle/Product/show.html.twig`)

Before:

```yaml
(new file)
```

After:

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content:
            app.sylius_shop_bundle_product_show:
                template: 'hooks/SyliusShopBundle_Product_show.html.twig'
                # priority: 0
```

### User Model Field Fixer

`src/Entity/User/AdminUser.php`

Before:

```php
<?php

class User
{
    private bool $locked = false;

    public function isLocked(): bool
    {
        return $this->locked;
    }
}
```

After:

```php
<?php

class User
{
    // TODO: propriete supprimee dans Sylius 2.0 — private bool $locked = false;

    public function isLocked(): bool
    {
        return $this->locked;
    }
}
```

### Workflow Migration Fixer

`config/packages/workflow.yaml` (issue reported on `config/packages/winzou_state_machine.yaml`)

Before:

```yaml
(new file)
```

After:

```yaml
framework:
    workflows:
        app_subscription:
            type: state_machine
            audit_trail:
                enabled: true
            marking_store:
                type: method
                property: state
            supports:
                - App\Entity\Subscription
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
```

## Low confidence

### Doctrine XML Mapping Fixer

`config/doctrine/Product.orm.xml`

Before:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping">
    <entity name="App\Entity\Product" table="sylius_product">
        <field name="name" type="string" length="255"/>
    </entity>
</doctrine-mapping>
```

After:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- TODO: convertir en attributs PHP (#[ORM\Entity], #[ORM\Column], etc.) -->
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping">
    <entity name="App\Entity\Product" table="sylius_product">
        <field name="name" type="string" length="255"/>
    </entity>
</doctrine-mapping>
```
