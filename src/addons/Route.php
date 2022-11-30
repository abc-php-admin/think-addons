<?php

declare(strict_types=1);

namespace think\addons;

use think\helper\Str;
use think\facade\Event;
use think\facade\Config;
use think\exception\HttpException;

class Route
{
    /**
     * 插件路由请求
     * @return mixed
     */
    public static function execute()
    {
        $app = app();
        $request = $app->request;

        $addon      = $request->route('addon');
        $controller = $request->route('controller');
        $action     = $request->route('action');
        Event::trigger('addons_begin', $request);

        if (empty($addon) || empty($controller) || empty($action)) {
            throw new HttpException(500, lang('addon can not be empty'));
        }

        $request->addon = $addon;
        // 设置当前请求的控制器、操作
        $request->setController($controller)->setAction($action);

        // 获取插件基础信息
        $info = get_addons_info($addon);
        
        if (!$info) {
            throw new HttpException(404, lang('addon %s not found', [$addon]));
        }
        if (!$info['status']) {
            throw new HttpException(500, lang('addon %s is disabled', [$addon]));
        }

        // 监听addon_module_init
        Event::trigger('AddonsModuleInit', $request);
        $class = get_addons_class($addon, 'controller', $controller);
        if (!$class) {
            throw new HttpException(404, lang('addon controller %s not found', [Str::studly($controller)]));
        }
        //自动加载当前配置信息
        self::loadConfig($request->addon);
        // 重写视图基础路径
        $config =  isset(Config::get($request->addon)["view"]) ? Config::get($request->addon)["view"] : Config::get('view');
        $config['view_path'] = $app->addons->getAddonsPath() . $addon . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        $config['tpl_replace_string'] = array_merge(
            ['__ADDONS__'=>"/static/addons/{$request->addon}"],
            @$config['tpl_replace_string'] ?: []
        );
        Config::set($config, 'view');
        if(!method_exists($class,$action)){
            throw new HttpException(404, lang('addon action %s not found', [$class.'->'.$action.'()']));
        }
        //加载指定函数
        $autoFun = ["function"];
        foreach (glob(getAddonPath($request->addon) . '/*.php') as $file) {
            in_array(str_replace(".php","",basename($file)),$autoFun) && include($file);
        }
        
        $newclass   = new \ReflectionClass($class);
        $reflection = $newclass->getMethod($action);
        $paramArr   = array();
        $args       = $request->param();
        foreach($reflection->getParameters() as $param)
        {
          if ($paramClass = $param->getClass()) {
             $calss            = $paramClass->getName();
             $paramArr[]       = new $calss;
          }
          elseif (isset($args[$param->getName()]))
          {
             $paramArr[] = $args[$param->getName()];
          }
          else
          {
              $paramArr[] = $param->getDefaultValue();
          }
          
        }
        Event::trigger('AddonsActionBegin', $paramArr);
        return $reflection->invokeArgs(new $class($app), $paramArr);
         
        // // 生成控制器对象
        // $instance = new $class($app);
        // $vars = [];
        // if (is_callable([$instance, $action])) {
        //     // 执行操作方法
        //     $call = [$instance, $action];
        // } elseif (is_callable([$instance, '_empty'])) {
        //     // 空操作
        //     $call = [$instance, '_empty'];
        //     $vars = [$action];
        // } else {
        //     // 操作不存在
        //     throw new HttpException(404, lang('addon action %s not found', [get_class($instance).'->'.$action.'()']));
        // }
        // Event::trigger('addons_action_begin', $call);

        // return call_user_func_array($call, $vars);
    }
    public static function loadConfig($name){
        $config = [];
        $path = getAddonPath($name). DIRECTORY_SEPARATOR."config/";
        foreach (glob($path."/*.php") as $file){
            if (is_file($file)){
                $filename = basename($file,".php");
                $config[$filename] = include $file;
            }
        }
        \think\facade\Config::set($config, $name);
    }
}