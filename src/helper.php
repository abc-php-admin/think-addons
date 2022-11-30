<?php

declare(strict_types=1);

use think\facade\Event;
use think\facade\Route;
use think\helper\Str;

\think\Console::starting(function (\think\Console $console) {
    $console->addCommands([
        'addons:config' => '\\think\\addons\\command\\SendConfig'
    ]);
});



// 插件类库自动载入
spl_autoload_register(function ($class) {

    $class = ltrim($class, '\\');

    $dir = app()->getRootPath();
    $namespace = 'addons';
    if (strpos($class, $namespace) === 0) {
        $class = substr($class, strlen($namespace));
        $path = '';
        if (($pos = strripos($class, '\\')) !== false) {
            $path = str_replace('\\', '/', substr($class, 0, $pos)) . '/';
            $class = substr($class, $pos + 1);
        }
        $path .= str_replace('_', '/', $class) . '.php';
        $dir .= $namespace . $path;
        if (file_exists($dir)) {
            include $dir;
            return true;
        }
        return false;
    }

    return false;
});
if (!function_exists('is_in_array')) {
    //判断是否在数组中
    function is_in_array(string $key,$arr=[]){
        foreach (["*",$key] as $v){
                if ( in_array(strtolower($v),$arr) ){
                    return true;
                    break;
                }
                
         }
         return false;
    }
}
if (!function_exists('hook')) {
    /**
     * 处理插件钩子
     * @param string $event 钩子名称
     * @param array|null $params 传入参数
     * @param bool $once 是否只返回一个结果
     * @return mixed
     */
    function hook($event, $params = null, bool $once = false)
    {
        $result = Event::trigger($event, $params, $once);

        return join('', $result);
    }
}
if (!function_exists('get_addon_ini')) {
    function get_addon_ini($name){
       $file =  app()->getRootPath()."addons/{$name}/info.ini";
       return parse_ini_file($file, true, INI_SCANNER_TYPED);
    }
}
if (!function_exists('get_addons_info')) {
    /**
     * 读取插件的基础信息
     * @param string $name 插件名
     * @return mixed|array
     */
    function get_addons_info($name)
    {
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }

        return $addon->getInfo();
    }
}

if (!function_exists('get_addons_config')) {
    /**
     * 获取配置信息
     * @param string $name 插件名
     * @param bool $type 是否获取完整配置
     * @return mixed|array
     */
    function get_addons_config($name, $type = true)
    {
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }
        return $addon->getConfig($type);
    }
}

if (!function_exists('get_addons_instance')) {
    /**
     * 获取插件的单例
     * @param string $name 插件名
     * @return mixed|null
     */
    function get_addons_instance($name)
    {
        static $_addons = [];
        if (isset($_addons[$name])) {
            return $_addons[$name];
        }
        $class = get_addons_class($name);
        if (class_exists($class)) {
            $_addons[$name] = new $class(app());

            return $_addons[$name];
        } else {
            return null;
        }
    }
}

if (!function_exists('get_addons_class')) {
    /**
     * 获取插件类的类名
     * @param string $name 插件名
     * @param string $type 返回命名空间类型
     * @param string $class 当前类名
     * @return mixed|string
     */
    function get_addons_class($name, $type = 'hook', $class = null)
    {
        $name = trim($name);
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            $class[count($class) - 1] = Str::studly(end($class));
            $class = implode('\\', $class);
        } else {
            $class = Str::studly(is_null($class) ? $name : $class);
        }
        switch ($type) {
            case 'controller':
                $namespace = '\\addons\\' . $name . '\\controller\\' . $class;
                break;
            default:
                $namespace = '\\addons\\' . $name . '\\Plugin';
        }
        return class_exists($namespace) ? $namespace : '';
    }
}
if (!function_exists('getAddonPath')) {
    function getAddonPath($name=""){
        !$name &&  $name   = app('request')->addon;
         return app()->getRootPath()."addons/{$name}";
    }
}
if (!function_exists('addons_path')) {
    function addons_path(){
        $request = app('request');
        $addons = $request->addon;
        $controller = $request->controller();
        $controller = str_replace('/', '.', $controller);
        $action = $request->action();
        return [$addons,$controller,$action];
    }
}
if (!function_exists("loadGlobalJs")){
    function loadGlobalJs(){
        $addons_path = app()->getRootPath()."addons/*/global.js";
        $data        = [];
        foreach (glob($addons_path) as $v){
                if (!preg_match("/addons\/(?<name>[A-Za-z0-9]+)\/global*/i",$v,$reg)){
                    continue;
                }
                $name     = $reg["name"];
                $addon    =  get_addon_ini($name);
                if ($addon["status"]!=1){
                    continue;
                }
                $data[]   = "//{$addon['name']} {$addon['title']} js start";
                $data[]   = file_get_contents($v);
                $data[]   = "//{$addon['name']} {$addon['title']} js end";
                
        }
        $path = str_replace("/",DIRECTORY_SEPARATOR,app()->getRootPath()."public/static/addons/global.js");
        file_put_contents($path,implode("\n",$data));
    }
}
if (!function_exists('addons_url')) {
    /**
     * 插件显示内容里生成访问插件的url
     * @param $url
     * @param array $param
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return mixed|bool|string
     */
    function addons_url($url = '', $param = [], $suffix = true, $domain = false)
    {
        $request = app('request');
        if (empty($url)) {
            // 生成 url 模板变量
            $addons = $request->addon;
            $controller = $request->controller();
            $controller = str_replace('/', '.', $controller);
            $action = $request->action();
        } else {
            $url = Str::studly($url);
            $url = parse_url($url);
            //防止重复生成
            $url['path'] = str_replace("Addons/","",$url['path']);
            if (isset($url['scheme'])) {
                $addons = strtolower($url['scheme']);
                $controller = $url['host'];
                $action = trim($url['path'], '/');
            } else {
                //todo new 
                $route = explode('/', $url['path']);
                $addons = $request->addon;
                $action = array_pop($route);
                $controller = array_pop($route) ?: $request->controller();
            }
            $controller = Str::snake((string)$controller);

            /* 解析URL带的参数 */
            if (isset($url['query'])) {
                parse_str($url['query'], $query);
                $param = array_merge($query, $param);
            }
        }
        return Route::buildUrl("@addons/{$addons}/{$controller}/{$action}", $param)->suffix($suffix)->domain($domain);
    }
}
