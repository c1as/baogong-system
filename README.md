# 报工助手 · Android 客户端（v3.0 · EasyTier 集成版）

报工管理系统的 Android WebView 客户端。页面与业务在服务端，本 App 负责承载网页、
拍照上传、文件下载、内嵌 VPN 隧道（WireGuard / EasyTier 互斥切换）与推送轮询。

## 工程
- 目录：`android/`（标准 Gradle 工程，AGP 8.7.3 / Gradle 8.14.3 / compileSdk 35 / Java 17）
- 包名：`com.baogong.helper`，versionName 3.0 / versionCode 3
- 纯 Java 代码构建 UI，无 layout XML
- 原生库仅含 `arm64-v8a`（`abiFilters 'arm64-v8a'`）：
  - WireGuard：tunnel 依赖提供的 `.so`
  - EasyTier：`libeasytier_android_jni.so`、`libeasytier_ffi.so`（源码编译，约 34 MB）

## 功能
- V2：release 签名、Splash 启动页、应用图标、HTTPS 开关、`allowBackup=false`、Android 15 边到边
- V3：断网离线页、App 内下载、WireGuard 隧道、推送轮询、通知渠道
- V3.0 新增：EasyTier mesh VPN 正式集成，与 WireGuard 在同一 App 内并存、连接互斥切换

## 构建
```bash
cd android
# 配置 JAVA_HOME(17)、ANDROID_HOME，local.properties 写入 sdk.dir
./gradlew assembleRelease
```
release 签名需自备 keystore（本仓库不含密钥与密码）。
`baogong-helper-v3.0-release.apk` 为已编译签名的安装快照。

## 文档
- `报工助手-安卓客户端开发文档.md`
- `报工助手-安卓APK开发准备清单.md`
- `报工助手-v2.0-开发说明与打包指南.md`
- `报工助手-v3.0-EasyTier集成说明.md`

## 说明
按需求未预置服务器地址，首次打开由用户填写。
两套 VPN 在系统层同一时刻只能有一条隧道活跃，因此 UI 层做互斥切换，避免冲突。
