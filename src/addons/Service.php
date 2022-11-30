<?php
declare(strict_types=1);

namespace think\addons;

use think\Route;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use think\addons\middleware\Addons;

/**
 * 插件服务 重构20221011
 * Class Service
 * @package think\addons
 */
class Service extends \think\Service
{
    protected $addons_path; //插件目录
    /**
     * 获取 addons 路径
     * @return mixed|string
     */
    public function getAddonsPath()
    {
        // 初始化插件目录
        $addons_path = $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        // 如果插件目录不存在则创建
        if (!is_dir($addons_path)) {
            @mkdir($addons_path, 0755, true);
        }

        return $addons_path;
    }
    public function register()
    {
        $this->addons_path = $this->getAddonsPath();
        // 加载系统语言包
        Lang::load([
            $this->app->getRootPath() . '/vendor/abcphp/think-addons/src/lang/zh-cn.php'
        ]);
        // 自动载入插件
        $this->autoload();
        //加载路由
        $this->loadRoute();
        // 加载插件系统服务
        $this->loadService();
        // 绑定插件容器
       //加载config当前config配置 全局废弃
        //自动加载全局的命令行
        $this->loadCommand();
        //$this->loadConfig();
        //静态文件一键复制
        $this->loadStatic();
        //全局js文件合拍
        $this->loadGlobal();
        $this->app->bind('addons', Service::class);
         // 加载插件事件
        $this->loadEvent();
        
        
    }

    public function boot()
    {
        $this->registerRoutes(function (Route $route) {
            // 路由脚本
            $execute = '\\think\\addons\\Route@execute';
          
            // // 注册插件公共中间件
            // if (is_file($this->app->addons->getAddonsPath() . 'middleware.php')) {
            //     $this->app->middleware->import(include $this->app->addons->getAddonsPath() . 'middleware.php', 'route');
            // }
            
            // 注册控制器路由
            $route->rule("addons/:addon/:controller/:action$", $execute)->middleware(Addons::class);
            // 自定义路由
            $routes = (array) Config::get('addons.route', []);
            //加载路由
            $routes = array_merge($routes, $this->loadConigRoute());
            
            foreach ($routes as $key => $val) {
                if (!$val) {
                    continue;
                }
                if (is_array($val)) {
                    $domain = $val['domain'];
                    $rules = [];
                    foreach ($val['rule'] as $k => $rule) {
                        [$addon, $controller, $action] = explode('/', $rule);
                        $rules[$k] = [
                            'addons'        => $addon,
                            'controller'    => $controller,
                            'action'        => $action,
                            'indomain'      => 1,
                        ];
                    }
                    $route->domain($domain, function () use ($rules, $route, $execute) {
                        // 动态注册域名的路由规则
                        foreach ($rules as $k => $rule) {
                            $route->rule($k, $execute)
                                ->name($k)
                                ->completeMatch(true)
                                ->append($rule);
                        }
                    });
                } else {
                    list($addon, $controller, $action) = explode('/', $val);
                    $route->rule($key, $execute)
                        ->name($key)
                        ->completeMatch(true)
                        ->append([
                            'addon' => $addon,
                            'controller' => $controller,
                            'action' => $action
                        ]);
                }
            }
            
        });
    }
    //加载配置选择中的路由配置
    private function loadConigRoute()
    {
        $routes = [];
        foreach (glob($this->getAddonsPath() . '*/config/route.php') as $addons_file) {
             $info      = pathinfo($addons_file);
             $eventName = $info["filename"];
             $path      = str_replace("/config","",$info["dirname"]);
             $name      = pathinfo($path, PATHINFO_FILENAME);
             $addon      =  get_addon_ini($name);
             if ($addon["status"]!=1){
                continue;
             }
             $routes = array_merge($routes,include($addons_file) );
        }
        return  $routes;
    }
    //加载插件事件
    private function loadAddonsEvent()
    {
        $hooks = [];
        foreach (glob($this->getAddonsPath() . '*/event/*.php') as $addons_file) {
             $info      = pathinfo($addons_file);
             $eventName = $info["filename"];
             $path      = str_replace("/event","",$info["dirname"]);
             $name      = pathinfo($path, PATHINFO_FILENAME);
             $addon      =  get_addon_ini($name);
             if ($addon["status"]!=1){
                continue;
             }
             $hooks[$eventName][] = ["\\addons\\{$name}\\event\\{$eventName}","handle"];
        }
        return $hooks;
    }
    /**
     * 插件事件
     */
    private function loadEvent()
    {
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks', []);
            // 初始化钩子
            $events  = $this->loadAddonsEvent();
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array) $values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), $key];
                }, $values));
                if ( isset($events[$key]) ){
                    $hooks[$key] = array_merge($hooks[$key],$events[$key]);
                }
            }
            Cache::set('hooks', $hooks);
        }
        Event::listenEvents($hooks);
        //如果在插件中有定义 AddonsInit，则直接执行
        if (isset($hooks['AddonsInit'])) {
           Event::trigger('AddonsInit');
        }
        //Event::listen('AddonsInit', '\addons\demo\event\AddonsInit');
        //Event::trigger('AddonsInit');
    }

    /**
     * 挂载插件服务
     */
    private function loadService()
    {
        $results = scandir($this->addons_path);
        $bind = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file($this->addons_path . $name)) {
                continue;
            }
            $addonDir = $this->addons_path . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            $service_file = $addonDir . 'service.ini';
            if (!is_file($service_file)) {
                continue;
            }
            $info = parse_ini_file($service_file, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $info);
        }
        $this->app->bind($bind);
    }

    /**
     * 自动载入插件
     * @return mixed|bool
     */
    private function autoload()
    {
        // 是否处理自动载入
        if (!Config::get('addons.autoload', true)) {
            return true;
        }
        $config = Config::get('addons');
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\Addons");
        // 读取插件目录中的php文件
        foreach (glob($this->getAddonsPath() . '*/*.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
           
            // 获取插件目录名
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            $addon    =  get_addon_ini($name);
            if ($addon["status"]!=1){
                continue;
            }
            // 找到插件入口文件
            if (strtolower($info['filename']) === 'plugin') {
                // 读取出所有公共方法
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
                
                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);
                
                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
                    
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    // 兼容手动配置项
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
            }
        }
        Config::set($config, 'addons');
    }

    
    //自动加载所有目录下的静态文件只在开发环境下加载 正式环境走安装
    public function loadStatic(){
        if (env("APP_DEBUG")==false){
            return true;
        }
        $addons_path = $this->getAddonsPath()."*/static";
        foreach (glob($addons_path) as $v){
            \abc\Files::linkAddonsDir($v);
        }
        
    }
    //全局js文件合并
    public function loadGlobal(){
        config("app.app_debug") && loadGlobalJs();
        
    }
    //在这路由
    public function loadRoute(){
        
        // 读取插件目录中的php文件
        foreach (glob($this->getAddonsPath() . '*/route.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
            // 获取插件目录名
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            $addon    =  get_addon_ini($name);
            if ($addon["status"]!=1){
                continue;
            }
            include($addons_file);
        }
    }
    //全局自动加载命令行 后续在次优化
    public function loadCommand(){
        $cmd = cache("addons:command");
        if (!$cmd){
            $cmd = [];
            foreach (glob($this->getAddonsPath(). '*/command/*.php') as $path){
                $filename = basename($path,".php");
                if (!preg_match("/addons\/(?<name>[A-Za-z0-9]+)\/command*/i",$path,$reg)){
                    continue;
                }
                $name     = $reg["name"];
                $addon    =  get_addon_ini($name);
                if ($addon["status"]!=1){
                    continue;
                }
                $cmd[] = "\\addons\\{$name}\\command\\{$filename}";
            }
            !config("app.app_debug") && cache("addons:command",$cmd,60*5);
        }
        // $arr = config("console.commands");
        // $arr = array_merge($arr,$cmd);
        // \think\facade\Config::set(["commands"=>$arr],"console");
        \think\Console::starting(function (\think\Console $console) use ($cmd){
            $console->addCommands($cmd);
        });
        
    }
    // //全局加载配置 废弃
    // public function loadConfig(){
    //     $config = \think\facade\Config::get("addons");
    //     //后面优化下只加载当前的模型
    //     foreach (glob($this->getAddonsPath(). '*/config/*.php') as $file){
    //         if (is_file($file) && preg_match("/addons\/(?<name>[A-Za-z0-9]+)\/config*/i",$file,$reg)){
    //             $config[$reg["name"]] = include $file;
    //         }
            
    //     }
    //     \think\facade\Config::set($config, 'addons');
    // }
    /**
     * 获取插件的配置信息
     * @param string $name
     * @return mixed|array
     */
    public function getAddonsConfig()
    {
        $name = $this->app->request->addon;
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }

        return $addon->getConfig();
    }
}
