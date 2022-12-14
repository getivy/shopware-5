<?php
/**
 * Implemented by HammerCode OÜ team https://www.hammercode.eu/
 *
 * @copyright HammerCode OÜ https://www.hammercode.eu/
 * @license proprietär
 * @link https://www.hammercode.eu/
 */

namespace IvyPaymentPlugin;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Tools\ToolsException;
use IvyPaymentPlugin\Models\IvyTransaction;
use Shopware\Components\Api\Resource\Translation as TranslationResource;
use Shopware\Components\Model\ModelManager;
use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;
use Shopware\Components\Plugin\PaymentInstaller;
use Shopware\Models\Payment\Payment;
use Shopware\Models\Shop\Shop;
use Shopware\Models\Translation\Translation;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Shopware-Plugin IvyPaymentPlugin.
 */
class IvyPaymentPlugin extends Plugin
{
    const IVY_PAYMENT_NAME = 'ivy_payment';
    const SALUTATION_NA = 'not_specified';

    private $translations = [
        'de_DE' => [
            'description' => 'Ivy - Nachhaltig bezahlen',
        ],
        'en_GB' => [
            'description' => 'Ivy - Sustainable Shopping',
        ],
    ];

    /**
     * @param InstallContext $context
     * @return void
     */
    public function install(Plugin\Context\InstallContext $context)
    {
        parent::install($context);
        $this->manageSchema();
        $this->managePayments($context);
        $this->setDefaults();
    }

    /**
     * @param UpdateContext $context
     * @return bool|void
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    public function update(UpdateContext $context)
    {
        $this->manageSchema();
        $this->managePayments($context);
        $context->scheduleClearCache(InstallContext::CACHE_LIST_ALL);
        $this->setDefaults();
        return true;
    }

    private function setDefaults()
    {
        Shopware()->Db()->executeQuery(
            'UPDATE s_core_config_elements e LEFT JOIN s_core_config_forms f on f.id = e.form_id
            SET e.value = :value
            WHERE f.name = :name AND e.name = :ename',
            ['name' => $this->getName(), 'ename' => 'logLevel', 'value' => 's:3:"400";']);
        Shopware()->Db()->executeQuery(
            'UPDATE s_core_config_elements e LEFT JOIN s_core_config_forms f on f.id = e.form_id
            SET e.value = :value
            WHERE f.name = :name AND e.name = :ename',
            ['name' => $this->getName(), 'ename' => 'isSandboxActive', 'value' => 's:1:"0";']);
        Shopware()->Db()->executeQuery(
            'UPDATE s_core_config_elements e LEFT JOIN s_core_config_forms f on f.id = e.form_id
            SET e.value = :value
            WHERE f.name = :name AND e.name = :ename',
            ['name' => $this->getName(), 'ename' => 'checkoutTitle', 'value' => 's:1:"0";']);
        Shopware()->Db()->executeQuery(
            'UPDATE s_core_config_elements e LEFT JOIN s_core_config_forms f on f.id = e.form_id
            SET e.value = :value
            WHERE f.name = :name AND e.name = :ename',
            ['name' => $this->getName(), 'ename' => 'checkoutSubTitle', 'value' => 's:1:"1";']);
        Shopware()->Db()->executeQuery(
            'UPDATE s_core_config_elements e LEFT JOIN s_core_config_forms f on f.id = e.form_id
            SET e.value = :value
            WHERE f.name = :name AND e.name = :ename',
            ['name' => $this->getName(), 'ename' => 'checkoutBanner', 'value' => 's:1:"1";']);
    }

    /**
     * @param ActivateContext $context
     * @return void
     */
    public function activate(ActivateContext $context)
    {
        parent::activate($context);
        $context->scheduleClearCache(InstallContext::CACHE_LIST_ALL);
    }

    /**
     * @param DeactivateContext $context
     * @throws OptimisticLockException
     */
    public function deactivate(DeactivateContext $context)
    {
        $this->setActiveFlag($context->getPlugin()->getPayments(), false);
    }

    /**
     * @param Collection $payments
     * @param bool $active
     * @throws OptimisticLockException
     */
    private function setActiveFlag(Collection $payments, $active)
    {
        /** @var ModelManager $em */
        $em = $this->container->get('models');

        /** @var Payment $payment */
        foreach ($payments as $payment) {
            $payment->setActive($active);
        }
        $em->flush();
    }

    /**
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container)
    {
        $container->setParameter('ivy_payment_plugin.plugin_dir', $this->getPath());
        $container->setParameter('ivy_payment_plugin.view_dir', $this->getPath() . '/Resources/views/');

        parent::build($container);
    }

    /**
     * creates new payments for shopware
     */
    private function managePayments(InstallContext $context)
    {
        $data = Shopware()->Db()->executeQuery("SELECT id, value FROM s_core_config_elements WHERE name = 'shopsalutations'")->fetch();
        $elementId = $data['id'];
        $salutations = \unserialize($data['value']);
        $salutations = \explode(',', $salutations);
        $salutations[] = self::SALUTATION_NA;
        $salutations = \serialize(\implode(',', \array_unique($salutations)));
        Shopware()->Db()->executeQuery('UPDATE s_core_config_elements SET value = ? WHERE id = ?', [$salutations, $elementId]);

        $datas = Shopware()->Db()->executeQuery("SELECT id, value FROM s_core_config_values WHERE element_id = ?", [$elementId])->fetchAll();
        foreach ($datas as $data) {
            $valueId = $data['id'];
            $salutations = \unserialize($data['value']);
            $salutations = \explode(',', $salutations);
            $salutations[] = self::SALUTATION_NA;
            $salutations = \serialize(\implode(',', \array_unique($salutations)));
            Shopware()->Db()->executeQuery('UPDATE s_core_config_values SET value = ? WHERE id = ?', [$salutations, $valueId]);
        }

        $em = Shopware()->Models();
        $mainShop = $em->getRepository(Shop::class)->findOneBy(['id' => 1]);
        $mainLocale = $mainShop->getLocale()->toString();

        /** @var PaymentInstaller $installer */
        $installer = $this->container->get('shopware.plugin_payment_installer');

        $options = [
            'name'                  => self::IVY_PAYMENT_NAME,
            'description'           => $this->translations[$mainLocale]['description'],
            'action'                => 'IvyPayment',
            'position'              => 0,
            'active'                => 1,
            'esdactive'             => 1,
            'additionalDescription' => '<img class="ivy-payment-logo" src="{link file=\'frontend/public/src/img/ivy.png\' fullPath}" alt="Ivy">',

        ];
        $payment = $installer->createOrUpdate($context->getPlugin()->getName(), $options);
        if ($payment) {
            $paymentId = $payment->getId();
            $em = $this->container->get('models');
            $shops = $em->getRepository(Shop::class)->findAll();
            foreach ($shops as $shop) {
                if ($shop->getId() === 1) {
                    continue;
                }
                $locale = $shop->getLocale()->toString();
                if (isset($this->translations[$locale])) {
                    $paymentTranslation = $em->getRepository(Translation::class)->findOneBy(
                        [
                            'type'   => TranslationResource::TYPE_PAYMENT,
                            'shopId' => $shop->getId(),
                        ]
                    );
                    if ($paymentTranslation === null) {
                        $paymentTranslation = new Translation();
                        $paymentTranslation->setType(TranslationResource::TYPE_PAYMENT);
                        $paymentTranslation->setShop($shop);
                        $paymentTranslation->setKey(1);
                    }
                    $data = \unserialize((string)$paymentTranslation->getData());
                    if (!\is_array($data)) {
                        $data = [];
                    }
                    $data[$paymentId] = $this->translations[$locale];
                    $paymentTranslation->setData(serialize($data));
                    $em->persist($paymentTranslation);
                    $em->flush($paymentTranslation);
                }
            }
        }
    }

    /**
     * Manage database tables on base of doctrine models
     *
     * @throws ToolsException
     * @throws \Exception
     */
    private function manageSchema()
    {
        $em = $this->container->get('models');
        $tool = new SchemaTool($em);
        $classes = [
            $em->getClassMetadata(IvyTransaction::class),
        ];

        $schemaManager = $em->getConnection()->getSchemaManager();

        // iterate classes
        /** @var ClassMetadata $classMetadata */
        foreach ($classes as $classMetadata) {
            $tableName = $classMetadata->getTableName();
            if (!$schemaManager->tablesExist([$tableName])) {
                $tool->createSchema([$classMetadata]);
            } else {
                $tool->updateSchema([$classMetadata], true); //true - saveMode and not delete other schemas
            }
        }
    }
}
