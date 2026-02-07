# php-txt-novel-reader

一个 **纯 PHP 的 txt 小说网页书库 + 阅读器**。  
主要为个人自用设计，同时也可以部署为一个轻量级的小说阅读站点。

A **pure PHP web-based txt novel library and reader**.  
Originally designed for personal use, but can also be deployed as a lightweight novel reading website.

---

## 项目说明 | About

- 本项目以 **简单、可用** 为目标  
- 不追求复杂架构，也不保证高性能或高安全性  
- 更适合作为个人阅读工具或参考实现

This project focuses on **simplicity and practicality**.  
It does **not** aim for high performance or enterprise-level security, and is best suited for personal use or as a reference project.

---

## 技术特点 | Features

- 纯 PHP 实现（无框架）
- 使用 txt + json 存储（无需数据库）
- 需要文件写权限
- 支持小说书库 / 书架
- 基础章节拆分（不保证 100% 准确）
- 阅读进度记录
- 阅读器界面（支持夜间模式、字体等基础设置）
- 移动端可用（基础响应式）
- 简单的 AI 问答功能（实验性）

Pure PHP, no framework  
Txt + JSON storage, no database required  
File write permission required  
Basic chapter parsing (accuracy not guaranteed)  
Bookshelf and reading progress support  
Reader UI with basic settings (night mode, font size, etc.)  
Mobile-friendly layout  
Simple AI Q&A feature (experimental)  

---

## 使用说明 | Usage

1. 将项目部署到支持 PHP 的 Web 环境  
2. 确保相关目录具有写权限  
3. 将 txt 小说文件放入指定目录  
4. 通过浏览器访问即可使用

1. Deploy the project to a PHP-enabled web server  
2. Make sure required directories are writable  
3. Put txt novel files into the specified directory  
4. Open it in your browser

> 本项目在 PHP 8 环境下测试通过，理论上不依赖特定 PHP 版本，但未做严格版本兼容测试。  
> Tested under PHP 8. Other versions may work but are not guaranteed.

---

## 注意事项 | Notes

- 本项目主要为个人使用而设计
- 不保证性能、安全性或章节解析准确性
- 请自行评估是否适合用于公开环境

This project is primarily intended for personal use.  
Performance, security, and chapter parsing accuracy are **not guaranteed**.  
Use it at your own discretion, especially in public environments.

---

## License

MIT License
