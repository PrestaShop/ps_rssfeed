<?php
/*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Rssfeed extends Module
{
    protected $templateFile;

    public function __construct()
    {
        $this->name = 'ps_rssfeed';
        $this->author = 'PrestaShop';
        $this->version = '1.0.1';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('RSS feed block', array(), 'Modules.Rssfeed.Admin');
        $this->description = $this->trans('Adds a block displaying a RSS feed.', array(), 'Modules.Rssfeed.Admin');

        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        $this->templateFile = 'module:ps_rssfeed/views/templates/hook/ps_rssfeed.tpl';
    }

    public function install()
    {
        return (parent::install()
            && Configuration::updateValue('RSS_FEED_TITLE', $this->trans('RSS feed', array(), 'Modules.Rssfeed.Admin'))
            && Configuration::updateValue('RSS_FEED_NBR', 5)
            && $this->registerHook('displayFooter')
        );
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('RSS_FEED_TITLE') ||
            !Configuration::deleteByName('RSS_FEED_NBR')
        ) {
            return false;
        }
        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitBlockRss')) {
            $errors = array();
            $urlfeed = Tools::getValue('RSS_FEED_URL');
            $title = Tools::getValue('RSS_FEED_TITLE');
            $nbr = (int)Tools::getValue('RSS_FEED_NBR');

            if ($urlfeed and !Validate::isAbsoluteUrl($urlfeed)) {
                $errors[] = $this->trans('Invalid feed URL', array(), 'Modules.Rssfeed.Admin');
            } elseif (!$title or empty($title) or !Validate::isGenericName($title)) {
                $errors[] = $this->trans('Invalid title', array(), 'Modules.Rssfeed.Admin');
            } elseif (!$nbr or $nbr <= 0 or !Validate::isInt($nbr)) {
                $errors[] = $this->trans('Invalid number of feeds', array(), 'Modules.Rssfeed.Admin');
            } elseif (stristr($urlfeed, $_SERVER['HTTP_HOST'] . __PS_BASE_URI__)) {
                $errors[] = $this->trans('You have selected a feed URL from your own website. Please choose another URL.', array(), 'Modules.Rssfeed.Admin');
            } elseif (!($contents = Tools::file_get_contents($urlfeed))) {
                $errors[] = $this->trans('Feed is unreachable, check your URL', array(), 'Modules.Rssfeed.Admin');
            } /* Even if the feed was reachable, We need to make sure that the feed is well formated */
            else {
                try {
                    new SimpleXMLElement($contents);
                } catch (Exception $e) {
                    $errors[] = $this->trans('Invalid feed: %message%', array('%message%' => $e->getMessage()), 'Modules.Rssfeed.Admin');
                }
            }

            if (!sizeof($errors)) {
                Configuration::updateValue('RSS_FEED_URL', $urlfeed);
                Configuration::updateValue('RSS_FEED_TITLE', $title);
                Configuration::updateValue('RSS_FEED_NBR', $nbr);

                $output .= $this->displayConfirmation($this->trans('The settings have been updated.', array(), 'Admin.Notifications.Success'));
                $this->_clearCache($this->templateFile);
            } else {
                $output .= $this->displayError(implode('<br />', $errors));
            }
        } else {
            $errors = array();
            if (stristr(Configuration::get('RSS_FEED_URL'), $_SERVER['HTTP_HOST'] . __PS_BASE_URI__)) {
                $errors[] = $this->trans('You have selected a feed URL from your own website. Please choose another URL.', array(), 'Modules.Rssfeed.Admin');
            }

            if (sizeof($errors)) {
                $output .= $this->displayError(implode('<br />', $errors));
            }
        }
        return $output . $this->renderForm();
    }

    public function hookDisplayFooter($params)
    {
        // Conf
        $title = strval(Configuration::get('RSS_FEED_TITLE'));
        $url = strval(Configuration::get('RSS_FEED_URL'));
        $nb = (int) (Configuration::get('RSS_FEED_NBR')) ? (int) (Configuration::get('RSS_FEED_NBR')) : 5;

        $cacheId = $this->getCacheId($this->name . '|' . date("YmdH"));
        if (!$this->isCached($this->templateFile, $cacheId)) {
            $rss_links = array();
            if ($url && ($contents = Tools::file_get_contents($url))) {
                try {
                    $xml = new SimpleXMLElement($contents);
                    $loop = 0;
                    if (!empty($xml->channel->item)) {
                        foreach ($xml->channel->item as $item) {
                            if (++$loop > $nb) {
                                break;
                            }
                            $rss_links[] = (array)$item;
                        }
                    }
                } catch (Exception $e) {
                    Tools::dieOrLog($this->trans('Error: invalid RSS feed in "%module_name%" module: %message%', array('%module_name%' => $this->name, '%message%' => $e->getMessage()), 'Modules.Rssfeed.Admin'));
                }
            }

            $this->smarty->assign(array(
                'title' => ($title ? $title : $this->trans('RSS feed', array(), 'Modules.Rssfeed.Admin')),
                'rss_links' => $rss_links
            ));
        }

        return $this->fetch($this->templateFile, $cacheId);
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Settings', array(), 'Admin.Global'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Block title', array(), 'Modules.Rssfeed.Admin'),
                        'name' => 'RSS_FEED_TITLE',
                        'desc' => $this->trans('Create a title for the block (default: \'RSS feed\').', array(), 'Modules.Rssfeed.Admin'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Add a feed URL', array(), 'Modules.Rssfeed.Admin'),
                        'name' => 'RSS_FEED_URL',
                        'desc' => $this->trans('Add the URL of the feed you want to use (sample: http://news.google.com/?output=rss).', array(), 'Modules.Rssfeed.Admin'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Number of threads displayed', array(), 'Modules.Rssfeed.Admin'),
                        'name' => 'RSS_FEED_NBR',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->trans('Number of threads displayed in the block (default value: 5).', array(), 'Modules.Rssfeed.Admin'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                )
            ),
        );

        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitBlockRss';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'RSS_FEED_TITLE' => Tools::getValue('RSS_FEED_TITLE', Configuration::get('RSS_FEED_TITLE')),
            'RSS_FEED_URL' => Tools::getValue('RSS_FEED_URL', Configuration::get('RSS_FEED_URL')),
            'RSS_FEED_NBR' => Tools::getValue('RSS_FEED_NBR', Configuration::get('RSS_FEED_NBR')),
        );
    }
}
