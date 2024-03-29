<?php (defined('BASEPATH')) OR exit('No direct script access allowed');

/* define the module locations and offsets */
Modules::$locations = array(
	APPPATH . 'modules/' => '../modules/',
	VENDORSPATH . 'modules/' => '../../' . VENDORSPATH .'modules/'
);

/* PHP5 spl_autoload */
spl_autoload_register('Modules::autoload');

/**
 * Modular Extensions - HMVC
 *
 * Adapted from the CodeIgniter Core Classes
 * @link	http://codeigniter.com
 *
 * Description:
 * This library provides functions to load and instantiate controllers
 * and module controllers allowing use of modules and the HMVC design pattern.
 *
 * Install this file as application/third_party/MX/Modules.php
 *
 * @copyright	Copyright (c) Wiredesignz 2010-09-09
 * @version 	5.3.4
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 **/
class Modules
{
	public static $routes, $registry, $locations;
	
	/**
	* Run a module controller method
	* Output from module is buffered and returned.
	**/
	public static function run($module) {
		
		$method = 'index';
		
		if(($pos = strrpos($module, '/')) != FALSE) {
			$method = substr($module, $pos + 1);		
			$module = substr($module, 0, $pos);
		}
	
		$controller = end(explode('/', $module));
		if ($controller != $module) $controller = $module.'/'.$controller;
		
		if($class = self::load($controller)) {
			
			if (method_exists($class, $method))	{
				ob_start();
				$args = func_get_args();
				$output = call_user_func_array(array($class, $method), array_slice($args, 1));
				$buffer = ob_get_clean();
				return ($output) ? $output : $buffer;
			}
		}
		
		log_message('error', "Module controller failed to run: {$controller}/{$method}");
	}
	
	/** Load a module controller **/
	public static function load($module) {
		(is_array($module)) ? list($module, $params) = each($module) : $params = NULL;	
		
		/* get the requested controller class name */
		$alias = strtolower(end(explode('/', $module)));

		/* return an existing controller from the registry */
		if (isset(self::$registry[$alias])) return self::$registry[$alias];
			
		/* get the module path */
		$segments = explode('/', $module);
			
		/* find the controller */
		list($class) = CI::$APP->router->locate($segments);

		/* controller cannot be located */
		if (empty($class)) return;

		/* set the module directory */
		$path = APPPATH.'controllers/'.CI::$APP->router->fetch_directory();
		
		/* load the controller class */
		self::load_file($class, $path);
		
		/* create and register the new controller */
		$class = ucfirst($class);
		self::$registry[$alias] = new $controller($params);
		return self::$registry[$alias];
	}
	
	/** Library base class autoload **/
	public static function autoload($class) {
		
		/* don't autoload CI_ prefixed classes or those using the config subclass_prefix */
		if (strstr($class, 'CI_') OR strstr($class, config_item('sublass_prefix'))) return;

		if(is_file($location = APPPATH.'core/'.$class.EXT)) {
			include_once $location;
		}	
	}

	/** Load a module file **/
	public static function load_file($file, $path, $type = 'other', $result = TRUE)	{
		
		$file = str_replace(EXT, '', $file);		
		$location = $path.$file.EXT;
		
		if ($type === 'other') {			
			if (class_exists($file, FALSE))	{
				log_message('debug', "File already loaded: {$location}");				
				return $result;
			}	
			include_once $location;
		} else { 
		
			/* load config or language array */
			include $location;

			if ( ! isset($$type) OR ! is_array($$type))				
				show_error("{$location} does not contain a valid {$type} array");

			$result = $$type;
		}
		log_message('debug', "File loaded: {$location}");
		return $result;
	}

	/** 
	* Find a file
	* Scans for files located within modules directories.
	* Also scans application directories for models and views.
	* Generates fatal error if file not found.
	**/
	public static function find($file, $module, $base) {
	
		$segments = explode('/', $file);

		$file = array_pop($segments);
		$file_ext = strpos($file, '.') ? $file : $file.EXT;
		
		$path = ltrim(implode('/', $segments).'/', '/');	
		$module ? $modules[$module] = $path : $modules = array();
		
		if ( ! empty($segments)) {
			$modules[array_shift($segments)] = ltrim(implode('/', $segments).'/','/');
		}	

		foreach (Modules::$locations as $location => $offset) {					
			foreach($modules as $module => $subpath) {
				$fullpath = $location.$module.'/'.$base.$subpath;
				if ($base == 'libraries/' AND is_file($fullpath.ucfirst($file_ext))) return array($fullpath, ucfirst($file));
				if (is_file($fullpath.$file_ext)) return array($fullpath, $file);
			}
		}
		
		/* is the file in an application directory? */
		if ($base == 'views/' OR $base == 'models/') {
			if (is_file(APPPATH.$base.$path.$file_ext)) return array(APPPATH.$base.$path, $file);
			show_error("Unable to locate the file: {$path}{$file_ext}");
		}

		return array(FALSE, $file);	
	}
	
	/** Parse module routes **/
	public static function parse_routes($module, $uri) {
		
		/* load the route file */
		if ( ! isset(self::$routes[$module])) {
			if (list($path) = self::find('routes', $module, 'config/') AND $path)
				self::$routes[$module] = self::load_file('routes', $path, 'route');
		}

		if ( ! isset(self::$routes[$module])) return;
			
		/* parse module routes */
		foreach (self::$routes[$module] as $key => $val) {						
					
			$key = str_replace(':any', '.+', str_replace(':num', '[0-9]+', $key));
			
			if (preg_match('#^'.$key.'$#', $uri)) {							
				if (strpos($val, '$') !== FALSE AND strpos($key, '(') !== FALSE) {
					$val = preg_replace('#^'.$key.'$#', $val, $uri);
				}

				return explode('/', $module.'/'.$val);
			}
		}
	}
}