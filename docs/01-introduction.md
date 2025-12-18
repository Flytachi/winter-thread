# 1. Introduction

Welcome to the official documentation for Winter Thread.

Winter Thread is a modern, object-oriented library designed to simplify background process management in PHP. It provides a robust, high-level API that abstracts away the complexities of low-level process control, making it easy to run, monitor, and interact with parallel tasks.

## Philosophy

The core philosophy behind Winter Thread is to provide a developer-friendly, Java-like threading model in a language that traditionally lacks built-in multi-threading capabilities. We achieve this by leveraging the power of separate OS-level processes, which offers several key advantages:

-   **True Parallelism**: Each task runs in its own process, allowing it to be scheduled on a different CPU core by the operating system, achieving true parallel execution.
-   **Isolation**: Processes are completely isolated from one another. A fatal error or memory leak in one child process will not affect the main application or other child processes.
-   **Simplicity**: By providing a clean `Thread` and `Runnable` API, developers can focus on their task logic instead of wrestling with `proc_open`, pipes, process signals, and serialization.

## Core Concepts

-   **Thread**: The main object that manages a child process. You create a `Thread` instance for each background task you want to run. It is responsible for starting, stopping, and monitoring the process.
-   **Runnable**: An interface that represents a unit of work. Any class that implements the `Runnable` interface can be executed by a `Thread`. The core logic of your task resides in the `run()` method.
-   **Runner Script**: An internal, executable PHP script (`runner`) that is responsible for bootstrapping the environment in the new process, deserializing the `Runnable` task, and executing it.

This library is designed for tasks that can be run independently and do not require complex inter-process communication (IPC), such as:
-   Processing heavy computational jobs (image/video encoding, report generation).
-   Running I/O-bound tasks (sending email batches, calling external APIs).
-   Offloading any long-running or blocking operation from the main application thread to keep it responsive.
