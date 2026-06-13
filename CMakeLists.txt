cmake_minimum_required(VERSION 3.20)
project(php_bloom C)

set(CMAKE_C_STANDARD 11)

set(PHP_CONFIG "" CACHE FILEPATH "Path to php-config")

if(NOT PHP_CONFIG)
        find_program(PHP_CONFIG_PROGRAM NAMES php-config)

        if(PHP_CONFIG_PROGRAM)
                set(PHP_CONFIG "${PHP_CONFIG_PROGRAM}" CACHE FILEPATH "Path to php-config" FORCE)
        endif()
endif()

if(NOT PHP_CONFIG)
        message(FATAL_ERROR "php-config not found. Configure with -DPHP_CONFIG=/path/to/php-config")
endif()

execute_process(
        COMMAND "${PHP_CONFIG}" --includes
        RESULT_VARIABLE PHP_CONFIG_RESULT
        OUTPUT_VARIABLE PHP_INCLUDE_FLAGS
        ERROR_VARIABLE PHP_CONFIG_ERROR
        OUTPUT_STRIP_TRAILING_WHITESPACE
)

if(NOT PHP_CONFIG_RESULT EQUAL 0)
    message(FATAL_ERROR "php-config --includes failed: ${PHP_CONFIG_ERROR}")
endif()

message(STATUS "PHP include flags: ${PHP_INCLUDE_FLAGS}")

separate_arguments(PHP_INCLUDE_FLAGS UNIX_COMMAND "${PHP_INCLUDE_FLAGS}")

set(PHP_INCLUDE_DIRS "")
foreach(flag IN LISTS PHP_INCLUDE_FLAGS)
    if(flag MATCHES "^-I(.+)")
        list(APPEND PHP_INCLUDE_DIRS "${CMAKE_MATCH_1}")
    endif()
endforeach()

message(STATUS "PHP include dirs: ${PHP_INCLUDE_DIRS}")

add_library(bloom_ide OBJECT
        bloom.c
        bloom_algo.c
        bloom_filter.c
)

target_include_directories(bloom_ide PRIVATE
        ${CMAKE_CURRENT_SOURCE_DIR}
        ${PHP_INCLUDE_DIRS}
)

target_compile_definitions(bloom_ide PRIVATE
        COMPILE_DL_BLOOM=1
)
