# php-txt-novel-reader

一个 **纯 PHP 的 txt 小说网页书库 + 阅读器**。  
主要为个人自用设计，也可以部署为一个轻量级的小说阅读站点。

> A lightweight PHP-based txt novel library and reader,  
> designed primarily for personal use.  
> The user interface is currently Chinese-only.

---
![IMG_4186](https://github.com/user-attachments/assets/e42b8c73-fe3d-426f-85d8-29ebb97980b7)
---

## 项目说明

- 本项目以 **简单、可用** 为目标  
- 不追求复杂架构，也不保证高性能或高安全性  
- 更适合作为个人阅读工具或参考实现

---

## 技术特点

- 纯 PHP 实现（无框架）
- 使用 txt + json 存储（无需数据库）
- 需要文件写权限
- 支持小说书库 / 书架
- 基础章节拆分（不保证 100% 准确）
- 阅读进度记录
- 阅读器界面（支持夜间模式、字体等基础设置）
- 移动端可用（基础响应式）
- 简单的 AI 问答功能（可选，仅支持 openai 兼容 key）

---

## 部署方式

1. 下载或克隆本项目  
2. 将项目解压到支持 PHP 的 Web 根目录  
3. 确保项目目录具备读写权限  
4. 通过浏览器访问站点，按提示完成首次初始化

无需数据库或额外依赖。  
项目体积约 **100KB**，适合个人使用或内网部署。

> 本项目在 PHP 8 环境下测试通过，  
> 理论上不依赖特定 PHP 版本，但未做严格的版本兼容测试。

---

## 注意事项

- 本项目主要为个人使用而设计
- 不保证性能、安全性或章节解析准确性
- AI 相关功能依赖第三方服务，是否使用由用户自行决定
- 请自行评估是否适合部署在公开网络环境中

---

## License

MIT License
