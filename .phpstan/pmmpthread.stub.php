<?php

declare(strict_types=1);

namespace pmmp\thread;

class ThreadSafe {
}

class ThreadSafeArray extends ThreadSafe implements \ArrayAccess, \Countable, \IteratorAggregate {
	public static function fromArray(array $array) : ThreadSafeArray {}
	public function offsetExists($offset) : bool {}
	public function offsetGet($offset) : mixed {}
	public function offsetSet($offset, $value) : void {}
	public function offsetUnset($offset) : void {}
	public function count() : int {}
	public function getIterator() : \Iterator {}
}

class Runnable extends ThreadSafe {
	public function run() : void {}
}

class Thread extends ThreadSafe {
	public function start(int $options = 0) : bool {}
	public function join() : bool {}
	public function isStarted() : bool {}
	public function isJoined() : bool {}
	public function isRunning() : bool {}
	public static function getCurrentThread() : ?Thread {}
}

class Worker extends Thread {
	public function stack(ThreadSafe $work) : int {}
	public function unstack() : ?ThreadSafe {}
	public function getStacked() : int {}
	public function isShutdown() : bool {}
	public function shutdown() : bool {}
	public function collect(?\Closure $collector = null) : int {}
}
