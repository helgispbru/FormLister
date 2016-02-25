<?php namespace FormLister;

include_once(MODX_BASE_PATH . 'assets/lib/APIHelpers.class.php');
include_once(MODX_BASE_PATH . 'assets/lib/Helpers/FS.php');
require_once(MODX_BASE_PATH . "assets/snippets/DocLister/lib/jsonHelper.class.php");
require_once(MODX_BASE_PATH . "assets/snippets/DocLister/lib/DLTemplate.class.php");

/**
 * Class FormLister
 * @package FormLister
 */
abstract class Core
{
    /**
     * @var array
     * Массив $_REQUEST
     */
    protected $_rq = array();

    protected $modx = null;

    protected $fs = null;

    /**
     * Идентификатор формы
     * @var mixed|string
     */
    protected $formid = '';

    /**
     * Массив настроек переданный через параметры сниппету
     * @var array
     * @access private
     */
    private $_cfg = array();

    /**
     * Шаблон для вывода по правилам DocLister
     * @var string
     */
    public $renderTpl = '';

    /**
     * Данные формы
     * fields - значения полей
     * errors - ошибки (поле => сообщение)
     * messages - сообщения
     * status - для api-режима, результат использования формы
     * @var array
     */
    private $formData = array(
        'fields'   => array(),
        'errors'   => array(),
        'messages' => array(),
        'status'   => false
    );

    protected $validator = null;

    /**
     * Массив с правилами валидации полей
     * @var array
     */
    protected $rules = array();

    /**
     * Флаг успешной валидации
     * @var bool
     */
    protected $isValid = true;

    /**
     * Если данные из формы отправлены, то true
     * @var bool
     */
    protected $isSubmitted = false;

    /**
     * Массив с именами полей, которые можно отправлять в форме
     * По умолчанию все поля разрешены
     * @var array
     */
    public $allowedFields = array();

    /**
     * Массив с именами полей, которые запрещено отправлять в форме
     * @var array
     */
    public $forbiddenFields = array();

    public function __construct($modx, $cfg = array())
    {
        $this->modx = $modx;
        $this->fs = \Helpers\FS::getInstance();
        if (isset($cfg['config'])) {
            $cfg = array_merge($this->loadConfig($cfg['config']), $cfg);
        }
        $this->setConfig($cfg);
        $this->allowedFields = $this->getCFGDef('allowedFields') ? explode(',',$this->getCFGDef('allowedFields')) : array();
        $this->disallowedFields = $this->getCFGDef('disallowedFields') ? explode(',',$this->getCFGDef('disallowedFields')) : array();
        $this->formid = $this->getCFGDef('formid');
    }

    /**
     * Установка значений в formData
     * Установка шаблона формы
     * Загрузка капчи
     */
    public function initForm() {
        if (!$this->isSubmitted) $this->setExternalFields($this->getCFGDef('defaultsSources','array'));
        if ($this->setRequestParams(array_merge($_GET, $_POST))) {
            $this->setFields($this->_rq);
            if ($this->getCFGDef('preserveDefaults')) $this->setExternalFields($this->getCFGDef('defaultsSources','array'));
        }
        $this->renderTpl = $this->formid ? $this->getCFGDef('formTpl') : '@CODE:'; //Шаблон по умолчанию
        $this->initCaptcha();
    }

    /**
     * Загрузка конфигов из файла
     *
     * @param $name string имя конфига
     * @return array массив с настройками
     */
    public function loadConfig($name)
    {
        //$this->debug->debug('Load json config: ' . $this->debug->dumpData($name), 'loadconfig', 2);
        if (!is_scalar($name)) {
            $name = '';
        }
        $config = array();
        $name = explode(";", $name);
        foreach ($name as $cfgName) {
            $cfgName = explode(":", $cfgName, 2);
            if (empty($cfgName[1])) {
                $cfgName[1] = 'custom';
            }
            $cfgName[1] = rtrim($cfgName[1], '/');
            switch ($cfgName[1]) {
                case 'custom':
                case 'core':
                    $configFile = dirname(__DIR__) . "/config/{$cfgName[1]}/{$cfgName[0]}.json";
                    break;
                default:
                    $configFile = $this->fs->relativePath($cfgName[1] . '/' . $cfgName[0] . ".json");
                    break;
            }

            if ($this->fs->checkFile($configFile)) {
                $json = file_get_contents($configFile);
                $config = array_merge($config, \jsonHelper::jsonDecode($json, array('assoc' => true), true));
            }
        }

        //$this->debug->debugEnd("loadconfig");
        return $config;
    }

    /**
     * Получение всего списка настроек
     * @return array
     */
    public function getConfig()
    {
        return $this->_cfg;
    }

    /**
     * Сохранение настроек вызова сниппета
     * @param array $cfg массив настроек
     * @return int результат сохранения настроек
     */
    public function setConfig($cfg)
    {
        if (is_array($cfg)) {
            $this->_cfg = array_merge($this->_cfg, $cfg);
            $ret = count($this->_cfg);
        } else {
            $ret = false;
        }
        return $ret;
    }

    /**
     * Загружает в formData данные не из формы
     * @param string $sources список источников
     * @param string $arrayParam название параметра с данными
     */
    public function setExternalFields($sources = 'array', $arrayParam = 'defaults') {
        $sources = explode(',',$sources);
        $fields = array();
        foreach ($sources as $source) {
            switch ($source) {
                case 'array':
                    if ($arrayParam) {
                        $fields = array_merge($fields,is_array($this->getCFGDef($arrayParam)) ? $this->getCFGDef($arrayParam) : \jsonHelper::jsonDecode($this->getCFGDef($arrayParam), array('assoc' => true), true));
                        $prefix = '';
                    }
                    break;
                case 'session':
                    $_source = explode(':',$source);
                    $fields = isset($_source[1]) && isset($_SESSION[$_source[1]]) ?
                        array_merge($fields,$_SESSION[$_source[1]]) :
                        array_merge($fields, $_SESSION);
                    $prefix = 'session';
                    break;
                case 'plh':
                    $_source = explode(':',$source);
                    $fields = isset($_source[1]) && isset($this->modx->placeholders[$_source[1]]) ?
                        array_merge($fields,$this->modx->placeholders[$_source[1]]) :
                        array_merge($fields, $this->modx->placeholders);
                    $prefix = 'plh';
                    break;
                case 'config':
                    $fields = array_merge($fields,$this->modx->config);
                    $prefix = 'config';
                    break;
                case 'cookie':
                    $_source = explode(':',$source);
                    $fields = isset($_source[1]) && isset($_COOKIE[$_source[1]]) ?
                        array_merge($fields,$_COOKIE[$_source[1]]) :
                        array_merge($fields, $_COOKIE);
                    $prefix = 'cookie';
                    break;
                default:
                    $_source = explode(':',$source);
                    $classname = $_source[0];
                    if (class_exists($classname) && isset($_source[1])) {
                        $obj = new $classname($this->modx);
                        if ($data = $obj->edit($_source[1])) {
                            $fields = array_merge($fields,$data->toArray());
                            $prefix = $classname;
                        }
                    }
            }
        }
        $this->setFields($fields,$this->getCFGDef('extPrefix') ? $prefix : '');
    }

    /**
     * Сохранение массива $_REQUEST
     * @param array $rq
     * @return int результат сохранения
     */
    public function setRequestParams($rq)
    {
        if (is_array($rq)) {
            $this->_rq = array_merge($this->_rq, $rq);
            $ret = count($this->_rq);
        } else {
            $ret = false;
        }
        $this->isSubmitted = isset($this->_rq['formid']) && $this->_rq['formid'] == $this->formid;
        return $ret;
    }

    /**
     * Полная перезапись настроек вызова сниппета
     * @param array $cfg массив настроек
     * @return int Общее число новых настроек
     */
    public function replaceConfig($cfg)
    {
        if (!is_array($cfg)) {
            $cfg = array();
        }
        $this->_cfg = $cfg;
        return count($this->_cfg);
    }

    /**
     * Получение информации из конфига
     *
     * @param string $name имя параметра в конфиге
     * @param mixed $def значение по умолчанию, если в конфиге нет искомого параметра
     * @return mixed значение из конфига
     */
    public function getCFGDef($name, $def = null)
    {
        return \APIHelpers::getkey($this->_cfg, $name, $def);
    }

    /*
     * Сценарий работы
     * Если форма отправлена, то проверяем данные
     * Если проверка успешна, то обрабатываем данные
     * Выводим шаблон
     */
    public function render()
    {
        if ($this->isSubmitted) {
            $this->validateForm();
            if ($this->isValid) {
                $this->process();
            }
        }
        return $this->renderForm();
    }

    /**
     * Готовит данные для вывода в шаблоне
     * @param bool $convertArraysToStrings
     * @return array
     */
    public function prerenderForm($convertArraysToStrings = false) {
        $plh = array_merge(
            $this->fieldsToPlaceholders($this->getFormData('fields'), 'value', $this->getFormData('status') || $convertArraysToStrings),
            $this->controlsToPlaceholders(),
            $this->errorsToPlaceholders(),
            array(
                'form.messages' => $this->renderMessages(),
                'captcha'=>$this->getField('captcha')
            )
        );
        return $plh;
    }

    /**
     * Вывод шаблона
     *
     * @param int $api
     * @return null|string
     */
    public function renderForm($api = 0)
    {
        if ($api) {
            return json_encode($this->getFormData());
        }
        $form = $this->parseChunk($this->renderTpl, $this->prerenderForm());
        return $form;
    }

    /**
     * Загружает данные в formData
     * @param array $fields массив полей
     * @param string $prefix добавляет префикс к имени поля
     */
    public function setFields($fields = array(),$prefix = '')
    {
        foreach ($fields as $key => $value) {
            if ((!in_array($key,$this->forbiddenFields) || in_array($key,$this->allowedFields))&& !empty($value)) {
                if ($prefix) $key = implode('.',array($prefix,$key));
                $this->setField($key, $value);
            }
        }
    }

    /**
     * Загружает класс-валидатор и создает его экземпляр
     * @return Validator|null
     */
    public function initValidator() {
        include_once(MODX_BASE_PATH . 'assets/snippets/FormLister/lib/Validator.php');
        $this->validator = new \FormLister\Validator();
        return $this->validator;
    }

    /**
     * Возвращает результат проверки полей
     * @return bool
     */
    public function validateForm()
    {
        $validator = $this->initValidator();
        $this->getValidationRules();
        if (!$this->rules || is_null($validator)) {
            return false;
        } //если правил нет, то не проверяем

        $result = true;

        //применяем правила
        foreach ($this->rules as $field => $rules) {
            $_field = $this->getField($field);
            $params = array($_field);
            foreach ($rules as $rule => $description) {
                if (is_array($description)) {
                    $params = array_merge($params,$description['params']);
                    $message = isset($description['message']) ? $description['message'] : 'Заполнено неверно.';
                } else {
                    $message = $description;
                }
                if (is_scalar($rule) && ($rule != 'custom') && method_exists($validator, $rule)) {
                    $result = call_user_func_array(array($validator, $rule), $params);
                } else {
                    if (isset($description['rule'])) $_rule = $description['rule'];
                    if ((is_object($_rule) && ($_rule instanceof Closure)) || is_callable($_rule)) {
                        $result = call_user_func_array($_rule, $params);
                    }
                }
                if (!$result) {
                    $this->addError(
                        $field,
                        $rule,
                        $message
                    );
                    break;
                }
            }
        }
        return $this->isValid;
    }

    /**
     * Возвращает массив formData или его часть
     * @param string $section
     * @return array
     */
    public function getFormData($section = '')
    {
        if ($section && isset($this->formData[$section])) {
            $out = $this->formData[$section];
        } else {
            $out = $this->formData;
        }
        return $out;
    }

    /**
     * Устанавливает статус формы, если true, то форма успешно обработана
     * @param $status
     */
    public function setFormStatus($status)
    {
        $this->formData['status'] = (bool)$status;
    }

    /**
     * Возвращает значение поля из formData
     * @param $field
     * @return string
     */
    public function getField($field)
    {
        return isset($this->formData['fields'][$field]) ? $this->formData['fields'][$field] : '';
    }

    /**
     * Сохраняет значение поля в formData
     * @param $field имя поля
     * @param $value значение поля
     */
    public function setField($field, $value)
    {
        $this->formData['fields'][$field] = $value;
    }

    /**
     * Добавляет в formData информацию об ошибке
     * @param $field имя поля
     * @param $type тип ошибки
     * @param $message сообщение об ошибке
     */
    public function addError($field, $type, $message)
    {
        $this->formData['errors'][$field][$type] = $message;
        $this->isValid = false;
    }

    /**
     * Добавляет сообщение в formData
     * @param string $message
     */
    public function addMessage($message = '')
    {
        if ($message) {
            $this->formData['messages'][] = $message;
        }
    }

    /**
     * Готовит данные для вывода в шаблон
     * @param array $fields массив с данными
     * @param string $suffix добавляет суффикс к имени поля
     * @param bool $split преобразование массивов в строки
     * @return array
     */
    public function fieldsToPlaceholders($fields = array(), $suffix = '', $split = false)
    {
        $plh = array();
        if (is_array($fields) && !empty($fields)) {
            foreach ($fields as $field => $value) {
                $field = array($field, $suffix);
                $field = implode('.', array_filter($field));
                if ($split && is_array($value)) {
                    $arraySplitter = $this->getCFGDef($field.'Splitter',$this->getCFGDef('arraySplitter','; '));
                    $value = implode($arraySplitter, $value);
                }
                $plh[$field] = \APIhelpers::e($value);
            }
        }
        return $plh;
    }

    /**
     * Готовит сообщения об ошибках для вывода в шаблон
     * @return array
     */
    public function errorsToPlaceholders()
    {
        $plh = array();
        foreach ($this->getFormData('errors') as $field => $error) {
            foreach ($error as $type => $message) {
                $classType = ($type == 'required') ? 'required' : 'error';
                $plh[$field . '.error'] = $this->parseChunk($this->getCFGDef('errorTpl',
                    '@CODE:<div class="error">[+message+]</div>'), array('message' => $message));
                $plh[$field . '.' . $classType . '.class'] = $this->getCFGDef($field . '.' . $classType . '.class',
                    $this->getCFGDef($classType . '.class', $classType));
            }
        }
        return $plh;
    }

    /**
     * Обработка чекбоксов, селектов, радио-кнопок перед выводом в шаблон
     * @return array
     */
    public function controlsToPlaceholders()
    {
        $plh = array();
        $formControls = explode(',',$this->getCFGDef('formControls'));
        foreach ($formControls as $field) {
            $value = $this->getField($field);
            if (empty($value)) {
                continue;
            } elseif (is_array($value)) {
                foreach ($value as $_value) {
                    $plh["s.{$field}.{$_value}"] = 'selected';
                    $plh["c.{$field}.{$_value}"] = 'checked';
                }
            } else {
                $plh["s.{$field}.{$value}"] = 'selected';
                $plh["c.{$field}.{$value}"] = 'checked';
            }
        }
        return $plh;
    }

    /**
     * Загрузка правил валидации
     */
    public function getValidationRules()
    {
        $rules = $this->getCFGDef('rules', '');
        $rules = \jsonHelper::jsonDecode($rules, array('assoc' => true));
        $this->rules = array_merge($this->rules,$rules);
    }

    /**
     * Готовит сообщения из formData для вывода в шаблон
     * @return null|string
     */
    public function renderMessages()
    {
        $formMessages = $this->getFormData('messages');
        $formErrors = $this->getFormData('errors');

        $requiredMessages = $filterMessages = array();
        if ($formErrors) {
            foreach ($formErrors as $field => $error) {
                $type = key($error);
                if ($type == 'required') {
                    $requiredMessages[] = $error[$type];
                } else {
                    $filterMessages[] = $error[$type];
                }
            }
        }

        $out = $this->parseChunk($this->getCFGDef('messagesTpl', '@CODE:<div class="form-messages">[+messages+]</div>'),
            array(
                'messages' => $this->renderMessagesGroup($formMessages, $this->getCFGDef('messagesOuterTpl', ''),
                    $this->getCFGDef('messagesSplitter', '<br>')),
                'required' => $this->renderMessagesGroup($requiredMessages,
                    $this->getCFGDef('messagesRequiredOuterTpl', ''),
                    $this->getCFGDef('messagesRequiredSplitter', '<br>')),
                'filters'  => $this->renderMessagesGroup($filterMessages,
                    $this->getCFGDef('messagesFiltersOuterTpl', ''),
                    $this->getCFGDef('messagesFiltersSplitter', '<br>')),
            ));

        return $out;
    }

    public function renderMessagesGroup($messages, $wrapper, $splitter)
    {
        $out = '';
        if (is_array($messages) && !empty($messages)) {
            $out = implode($splitter, $messages);
            $wrapperChunk = $this->getCFGDef($wrapper, '@CODE: [+messages+]');
            $out = $this->parseChunk($wrapperChunk, array('messages' => $out));
        }
        return $out;
    }

    /**
     * Формирует текст письма для отправки
     * @return null|string
     */
    public function renderReport()
    {
        $tpl = $this->getCFGDef('reportTpl');
        if (empty($tpl)) {
            $tpl = '@CODE:';
            foreach($this->getFormData('fields') as $key => $value) {
                $tpl .= "[+{$key}+]: [+{$key}.value+]".PHP_EOL;
            }
        }
        $_tpl = $this->renderTpl;
        $this->renderTpl = $tpl;
        $out = $this->renderForm(true);
        $this->renderTpl = $_tpl;
        return $out;
    }


    /**
     * Установка адресов в PHPMailer, из eForm
     * @param $mail - объект почтового класса
     * @param $type - тип адреса
     * @param $addr - адрес
     */
    public function addAddressToMailer(&$mail, $type, $addr)
    {
        if (empty($addr)) {
            return;
        }
        $a = array_filter(array_map('trim', explode(',', $addr)));
        foreach ($a as $address) {
            switch ($type) {
                case 'to':
                    $mail->AddAddress($address);
                    break;
                case 'cc':
                    $mail->AddCC($address);
                    break;
                case 'bcc':
                    $mail->AddBCC($address);
                    break;
                case 'replyTo':
                    $mail->AddReplyTo($address);
            }
        }
    }

    /**
     * Отправка письма
     * @return bool
     */
    public function sendForm()
    {
        //если отправлять некуда или незачем, то делаем вид, что отправили
        if (!$this->getCFGDef('to') || $this->getCFGDef('noemail')) {
            $this->setFormStatus(true);
            return true;
        }

        $isHtml = $this->getCFGDef('isHtml', 1);
        $report = $this->renderReport();

        //TODO: херня какая-то
        $report = !$isHtml ? htmlspecialchars_decode($report) : nl2br($report);

        $this->modx->loadExtension('MODxMailer');
        $mail = $this->modx->mail;
        $mail->IsHTML($isHtml);
        $mail->From = $this->getCFGDef('from', $this->modx->config['emailsender']);
        $mail->FromName = $this->getCFGDef('fromname', $this->modx->config['site_name']);
        $mail->Subject = $this->getCFGDef('subjectTpl') ?
            $this->parseChunk($this->getCFGDef('subjectTpl'),$this->fieldsToPlaceholders($this->getFormData('fields'))) :
            $this->getCFGDef('subject');
        $mail->Body = $report;
        $this->addAddressToMailer($mail, "replyTo", $this->getCFGDef('replyTo'));
        $this->addAddressToMailer($mail, "to", $this->getCFGDef('to'));
        $this->addAddressToMailer($mail, "cc", $this->getCFGDef('cc'));
        $this->addAddressToMailer($mail, "bcc", $this->getCFGDef('bcc'));

        //AttachFilesToMailer($modx->mail,$attachments);

        $result = $mail->send();
        if (!$result) {
            $this->addMessage("Произошла ошибка при отправке формы ({$mail->ErrorInfo})");
            //$modx->mail->ErrorInfo; - добавить потом в сообщения отладки
        } else {
            $mail->ClearAllRecipients();
            $mail->ClearAttachments();

        }
        return $result;
    }

    /**
     * Проверка повторной отправки формы
     * @return bool
     */
    public function checkSubmitProtection()
    {
        $result = false;
        if ($protectSubmit = $this->getCFGDef('protectSubmit', 1)) {
            $hash = $this->getFormHash();
            if (isset($_SESSION[$this->formid . '_hash']) && $_SESSION[$this->formid . '_hash'] == $hash && $hash != '') {
                $result = true;
                $this->addMessage('Данные успешно отправлены. Нет нужды отправлять данные несколько раз.');
            }
        }
        return $result;
    }

    /**
     * Проверка повторной отправки в течение определенного времени, в секундах
     * @return bool
     */
    public function checkSubmitLimit()
    {
        $submitLimit = $this->getCFGDef('submitLimit', 60);
        $result = false;
        if ($submitLimit > 0) {
            if (time() < $submitLimit + $_SESSION[$this->formid . '_limit']) {
                $result = true;
                $this->addMessage('Вы уже отправляли эту форму, попробуйте еще раз через ' . round($submitLimit / 60, 0) . ' мин.');
            } else {
                unset($_SESSION[$this->formid . '_limit'], $_SESSION[$this->formid . '_hash']);
            } //time expired
        }
        return $result;
    }

    public function setSubmitProtection()
    {
        if ($this->getCFGDef('submitLimit', 1)) {
            $_SESSION[$this->formid . '_hash'] = $this->getFormHash();
        } //hash is set earlier
        if ($this->getCFGDef('submitLimit', 60) > 0) {
            $_SESSION[$this->formid . '_limit'] = time();
        }
    }

    public function getFormHash()
    {
        $hash = '';
        $protectSubmit = $this->getCFGDef('protectSubmit', 1);
        if (!is_numeric($protectSubmit)) { //supplied field names
            $protectSubmit = explode(',', $protectSubmit);
            foreach ($protectSubmit as $field) {
                $hash .= $this->getField($field);
            }
        } else //all required fields
        {
            foreach ($this->rules as $field => $rules) {
                foreach ($rules as $rule => $description) {
                    if ($rule == 'required') {
                        $hash .= $this->getField($field);
                    }
                }
            }
        }
        if ($hash) {
            $hash = md5($hash);
        }
        return $hash;
    }

    public function parseChunk($name, $data, $parseDocumentSource = false)
    {
        $out = null;
        $out = \DLTemplate::getInstance($this->modx)->parseChunk($name, $data, $parseDocumentSource);
        return $out;
    }

    /**
     * Загружает класс капчи
     */
    public function initCaptcha()
    {
        if ($captcha = $this->getCFGDef('captcha')) {
            $wrapper = MODX_BASE_PATH . "assets/snippets/FormLister/lib/captcha/{$captcha}/wrapper.php";
            if ($this->fs->checkFile($wrapper)) {
                include_once($wrapper);
                $wrapper = $captcha.'Wrapper';
                $captcha = new $wrapper ($this);
                $this->rules[$this->getCFGDef('captchaField', 'vericode')] = $captcha->getRule();
                $this->setField('captcha',$captcha->getPlaceholder());
            }
        }
    }

    public function getMODX() {
        return $this->modx;
    }

    public function getFormId() {
        return $this->formid;
    }

    /**
     * Обработка формы, определяется контроллерами
     *
     * @return mixed
     */
    abstract public function process();
}