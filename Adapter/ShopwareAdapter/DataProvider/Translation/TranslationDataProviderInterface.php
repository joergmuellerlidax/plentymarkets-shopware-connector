<?php

namespace ShopwareAdapter\DataProvider\Translation;

use PlentyConnector\Connector\TransferObject\Product\Property\Property;
use PlentyConnector\Connector\TransferObject\Product\Property\Value\Value;
use PlentyConnector\Connector\ValueObject\Identity\Identity;
use Shopware\Models\Article\Configurator\Group as ConfiguratorGroupModel;
use Shopware\Models\Article\Configurator\Option as ConfiguratorOptionModel;
use Shopware\Models\Property\Option as OptionModel;
use Shopware\Models\Property\Value as ValueModel;
use Shopware\Models\Shop\Shop as ShopModel;

/**
 * Interface TranslationDataProviderInterface
 */
interface TranslationDataProviderInterface
{
    /**
     * @param Identity $identity
     *
     * @return ShopModel[]
     */
    public function getShopsByLocaleIdentitiy(Identity $identity);

    /**
     * @param Property $property
     *
     * @return OptionModel|null
     */
    public function getPropertyOptionByName(Property $property);

    /**
     * @param Value $value
     *
     * @return ValueModel|null
     */
    public function getPropertyValueByValue(Value $value);

    /**
     * @param Property $property
     *
     * @return ConfiguratorGroupModel|null
     */
    public function getConfigurationGroupByName(Property $property);

    /**
     * @param Value $value
     *
     * @return ConfiguratorOptionModel|null
     */
    public function getConfigurationOptionByName(Value $value);
}
