# 报工助手 · 安卓 APK 开发准备清单（草案·待确认）

> 来源：仓库 `c1as/baogong-system` → `docs/安卓客户端开发文档.md` v1.0（2026-11-21）
> 状态：**已完成 —— V2 + V3 全部开发并构建成功（剔除「预置服务器地址」）。源码在 `/workspace/baogong-android/`，成品 APK 为 `报工助手-v2.0-release.apk` / `报工助手-v2.0-debug.apk`。详见《报工助手-v2.0-开发说明与打包指南.md》**
> 工程副本：`/workspace/baogong-android/`　开发文档：`/workspace/报工助手-安卓客户端开发文档.md`　基线包：`/workspace/baogong-helper-v1.0.apk`

---

## 一、文档讲了什么（速览）

这份文档描述的是一个叫「**报工助手**」的 Android 客户端，它**不是独立 App，而是一个 WebView 外壳**：把服务器上「报工管理系统」的网页原样装进手机里。页面、业务、数据全在服务端（PHP 8 + SQLite），App 只负责承载网页、接管拍照上传和文件下载。

一句话概括：**服务端改功能不用重新打包 APK；只有入口地址或底层交互方式变了才需要更新客户端。**

| 章节 | 要点 |
| --- | --- |
| 1 定位 | 单 Activity、零第三方 UI 框架、包体小；手机需与服务器同内网，或走 VPN / 公网 IPv6 |
| 2 技术栈 | Java 17、包名 `com.baogong.helper`、minSdk 24 / targetSdk 34 / compileSdk 35、AGP 8.7.3、Gradle 8.14.3、仅依赖 `androidx.core:core:1.13.1` |
| 3 目录结构 | 核心代码只有 `MainActivity.java` 一个文件，UI 全部用 Java 代码构建，无 layout XML |
| 4 构建运行 | `./gradlew assembleDebug`，产物 `app/build/outputs/apk/debug/app-debug.apk`，`adb install -r` 安装 |
| 5 启动流程 | 读 `bg_shell/server_url` → 空则弹「服务器地址」对话框（不可取消）→ 非空直接 `loadUrl` → 主文档失败显示错误页 |
| 6 实现细节 | WebView 配置、URL 归一化、`<input type="file">` 拍照/选图（FileProvider）、DownloadManager 带 Cookie 下载、Cookie 登录态、返回键与错误页 |
| 7 后端契约 | 相对路径资源、`bg_session` Cookie、标准 file input、`Content-Disposition: attachment` 下载、禁用 `window.open` |
| 8 权限 | `INTERNET`（必需）、`WRITE_EXTERNAL_STORAGE`（仅 Android 9 及以下，`maxSdkVersion=28`） |
| 9 签名发布 | 当前**只产出 debug 签名包**，未配置 release 签名，不适合对外分发 |
| 10 扩展规划 | 内嵌 WireGuard（规划中，未实现）；注意 IncludedApplications 会让系统 DownloadManager 下载失败 |
| 11 公网访问 | IPv6 + DDNS + 8443 + Let's Encrypt DNS-01 + Cloudflare 兜底 |
| 12 已知限制 | 无离线能力、不支持多标签/深链接/系统分享、HTTP 明文仅限内网、未适配 Android 15 边到边 |
| 13 排错 | 白屏、上传失败、下载失败、反复登录、构建失败、安装被拒的排查表 |
| 14 变更记录 | v1.0 首版 |

---

## 二、一个重要发现：源码和现成 APK 都已经有了

我逐条核对了仓库 `android/` 下的 15 个文件，**与文档描述完全一致**，不是"只有文档没有代码"：

| 核对项 | 文档 | 实际仓库 | 结论 |
| --- | --- | --- | --- |
| `MainActivity.java` 常量 `bg_shell` / `server_url` / 1001 / 1002 | ✓ | ✓ | 一致 |
| `normalizeUrl()` 补 `http://`、补 `/`、含空格判无效 | ✓ | ✓ | 一致 |
| 相机 `cache/camera/shot_*.jpg` + `fileprovider` | ✓ | ✓ | 一致 |
| 下载带 `Cookie` / `User-Agent`、Android 9 以下申请存储权限 | ✓ | ✓ | 一致 |
| 菜单：服务器地址 / 重新加载 / 清除登录状态 / 使用说明 | ✓ | ✓ | 一致 |
| `compileSdk 35`、Java 17、AGP 8.7.3、Gradle 8.14.3 | ✓ | ✓ | 一致 |
| `gradle-wrapper.jar` 存在且 `.gitignore` 用 `!` 保留 | ✓ | ✓ | 一致 |
| release 签名配置 | 未配置 | 未配置 | 一致 |

另外仓库根目录自带 **`baogong-helper-v1.0.apk`（1.9 MB，已签名）**。

> **结论：这一步不需要"从零开发"。** 后续工作实际是三选一：①用现成源码构建出自己的 APK；②在现成源码上改造定制；③直接拿现成 APK 用。请把这个作为决策前提。

---

## 三、开发前必须想清楚的三件事

1. **这个 App 装完是"空壳"**：首次打开必须手动填服务器地址（如 `192.168.1.10:8080`），且手机要能访问到那台跑着 PHP 报工系统的机器。没有服务器，装了也是白屏。
2. **当前只能出 debug 包**：文档第 9 节明确写了未配置 release 签名。内部自用没问题，对外分发需要配 keystore 和 4 个环境变量。
3. **业务功能不在 APK 里**：零件管理、拍照识别、统计导出等都是服务端 `public/` 里的网页，迭代它们**不用重新打包**。想加"功能"，多数时候该改的是 PHP 前端，不是 Android 代码。

---

## 四、构建环境差距（沙箱实测结果）

| 项 | 文档要求 | 当前沙箱 | 处理 |
| --- | --- | --- | --- |
| JDK | **17** | OpenJDK **20**（Zulu） | 需装 17；AGP 8.7.3 用 20 会报版本错误 |
| Android SDK | Platform 35 + Build-Tools | **未安装**，`ANDROID_HOME` 未设置 | 需装 commandline-tools 后拉 SDK |
| `adb` / `sdkmanager` | 需要 | 均不存在 | 随 platform-tools / cmdline-tools 安装 |
| Gradle | 8.14.3（wrapper） | 系统另有 Gradle；`gradlew` 与 `gradle-wrapper.jar` 齐全且可执行 | 直接用 `./gradlew`，会自动下载 8.14.3 |
| 磁盘 | — | 剩余 254 GB | 充足 |
| 网络 | 需拉 Maven 依赖 | 可访问 | 可用 |

### 补齐命令（确认要构建后再执行）

```bash
# 1) 装 JDK 17（沙箱已有 sdkman）
sdk install java 17.0.13-tem      # 若该版本不可用，先 sdk list java 挑一个 17.x
sdk use java 17.0.13-tem
java -version

# 2) 装 Android commandline-tools
mkdir -p /opt/android-sdk/cmdline-tools && cd /opt/android-sdk/cmdline-tools
curl -o cmdtools.zip https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
unzip -q cmdtools.zip && mv cmdline-tools latest && rm cmdtools.zip

# 3) 设置环境变量并下载 SDK 组件
export ANDROID_HOME=/opt/android-sdk
export PATH=$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools:$PATH
yes | sdkmanager --licenses
sdkmanager "platforms;android-35" "build-tools;35.0.0" "platform-tools"

# 4) 指向 SDK 并构建
echo "sdk.dir=/opt/android-sdk" > /workspace/baogong-android/local.properties
cd /workspace/baogong-android
./gradlew assembleDebug          # 首次会下载 Gradle 8.14.3 与 AGP/androidx 依赖，需联网
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

**高频报错对照**（文档第 13 节）：`cmdline-tools not found` → 检查 SDK 目录结构是否为 `cmdline-tools/latest/`；`Gradle build failed` → `./gradlew clean` 后重编；构建失败先查 JDK 17、SDK 35、`local.properties`、`gradle-wrapper.jar` 是否存在；安装被拒 → 允许未知来源，或先卸载同包名的旧签名应用。

---

## 五、《App 需求规格说明书（草案·待确认）》

> 以下内容以「文档明确」和【AI建议】区分，【AI建议】不是你的原话，请核对。

### ① 项目概述
「报工助手」是面向机加工车间「报工管理系统」（PHP + SQLite 内网 Web 系统）的 Android WebView 外壳。解决的是：手机浏览器访问内网系统要反复输网址、登录态易丢、拍照上传和 CSV 下载体验割裂的问题，用一个固定入口把网页封装成可安装的 App。

### ② 用户画像
- **目标用户**：车间操作工 / 车间管理员，单人或小团队使用（文档第 1 节、项目文档第 1 节）。
- **使用场景**：车间内网 Wi-Fi 下随手报工、拍照识别报工单、查看统计、导出明细。
- **痛点**：网址难记、手机浏览器登录态不稳、拍照与下载走系统流程不顺。
- 【AI建议：非面向公网大众，不需要账号体系 / 推送 / 应用商店上架；以内部自用为主】

### ③ 版本迭代规划
| 版本 | 内容 | 状态 |
| --- | --- | --- |
| **V1（MVP）** | WebView 外壳、服务器地址配置与 URL 归一化、错误页、拍照/选图上传、带 Cookie 的下载接管、四项菜单、Cookie 登录态、返回键导航 | **文档已实现，源码已具备** |
| **V2**（本轮已实现） | release 签名配置、启动页 / 自定义图标、强制 HTTPS + 网络安全配置、关闭 `allowBackup`、Android 15 边到边适配。**「预置服务器地址」按你的要求不做** | 已完成 |
| **V3**（本轮已实现） | 内嵌 WireGuard（配置 Keystore 加密存储 + 系统 VPN 授权）、App 内下载替代 DownloadManager、离线友好提示页、推送（前台轮询 + 通知，客户端就绪） | 已完成 |

> **裁剪说明**：V1 已经完整可用，本轮不新增任何业务功能。业务功能（零件、订单、拍照识别、统计导出）属于服务端 `public/` 的迭代范围，**不需要重新打包 APK**，因此全部不放入 V1，避免把简单套壳做成大工程。

### ④ 页面文字原型
| 页面 | 主要组件与文字 | 点击后行为 |
| --- | --- | --- |
| 主页面（MainActivity + 全屏 WebView） | 标题「报工助手」；内容 = 服务端网页 | 展示服务端页面；返回键优先走 WebView 历史，无历史才退出 |
| 「服务器地址」对话框（首次，不可取消） | 提示「填写报工系统访问地址，如 192.168.1.10:8080 或 https://bg.example.com。手机需与服务器处于同一内网（或已连接 VPN）。」；输入框 hint `192.168.1.10:8080`；按钮「保存并打开」 | 保存并打开 → 归一化地址 → 存入 `bg_shell/server_url` → `loadUrl()`；输入非法则输入框报错「请输入有效的服务器地址」 |
| 错误页 | 标题「无法连接服务器」+ 具体 URL 与错误描述 + 提示「请确认手机已连接内网，或检查服务器地址是否正确。」；按钮「重新加载」「修改服务器地址」 | 重新加载 → `webView.reload()`；修改服务器地址 → 弹出可取消的地址对话框 |
| 右上角菜单 | 服务器地址 / 重新加载 / 清除登录状态 / 使用说明 | 服务器地址 → 地址对话框（可取消，保存后立即重新加载）；重新加载 → 重载；清除登录状态 → 清空所有 Cookie + 清缓存 + 刷新，提示「已清除登录状态」；使用说明 → 说明弹窗 |
| 系统选择界面 | 标题「选择图片或文件」，含系统相机入口 | 由网页 `<input type="file">` 触发；结果回传 WebView；取消拍照删除临时文件 |
| 系统下载通知 | 「已开始下载：<文件名>」 | 交系统 DownloadManager，保存到手机「下载」目录 |
| 【AI建议：服务端承载页（非本 App 开发范围）】 | 登录页、零件管理、订单管理、报工录入、拍照识别、报工统计、导出、设置 | 由 `public/index.html` + `app.js` 提供，App 不干预 |

### ⑤ 功能清单
| 功能 | 级别 | 归属 |
| --- | --- | --- |
| WebView 承载网页、JS / DOM Storage / 第三方 Cookie | 必选 | V1 |
| 服务器地址配置 + URL 归一化 + 持久化 | 必选 | V1 |
| 加载失败错误页与重新加载 | 必选 | V1 |
| 拍照 / 文件选择上传（FileProvider） | 必选 | V1 |
| 带 Cookie 的文件下载接管 | 必选 | V1 |
| 菜单四项、登录态清除、返回键导航 | 必选 | V1 |
| release 签名、预置地址、启动页、HTTPS 强化 | 可选 | V2【AI建议】 |
| 内嵌 WireGuard、App 内下载、离线提示、推送 | 可选 | V3【AI建议】 |

### ⑥ 页面清单
| 页面 | 对应模块 |
| --- | --- |
| 主 WebView 页 | `MainActivity.onCreate` / `configureWebView` |
| 服务器地址对话框 | `showServerDialog` / `normalizeUrl` |
| 错误页 | `buildErrorView` / `showError` |
| 菜单与说明弹窗 | `onCreateOptionsMenu` / `showAbout` |
| 文件选择与相机 | `buildFileChooserIntent` / `buildCameraIntent` / `onActivityResult` |
| 下载 | `enqueueDownload` / `onRequestPermissionsResult` |

### ⑦ 手机权限清单
| 权限 | 用途 | 是否必需 |
| --- | --- | --- |
| `INTERNET` | 访问服务器 | **必需** |
| `WRITE_EXTERNAL_STORAGE` | Android 9 及以下保存下载 | 条件必需，`maxSdkVersion=28` |
| 相机 / 相册 | 拍照与选图 | **无需声明**：走系统相机应用 + 文件选择器，仅用 `FileProvider` 暴露 `cache/camera/` |
| 通知 | 下载完成提醒 | 无需声明，由系统 DownloadManager 发出 |
| 定位 / 推送 / 通讯录等 | — | 不需要 |
| 【AI建议：`VpnService` + 前台服务 + 通知权限】 | 内嵌 WireGuard 时才需要 | V3 才用 |

### ⑧ 技术选型方案
**沿用仓库现有方案：原生 Java 17 + 单 Activity + 系统 WebView + AndroidX `FileProvider`，AGP 8.7.3 / Gradle 8.14.3，纯代码构建 UI，一套代码只出 Android。**

- **理由**：本场景是"给已有 Web 系统套壳"，不是多页面原生 App；已有源码逐条可用、包体最小（约 1.9 MB）、零第三方 UI 框架、迭代服务端不用动客户端。
- **关于跨端的取舍**：我通常优先推荐 Flutter 一套代码双端，但**这里不推荐**。原因是需求是承载既有网页、且只需要 Android；换 Flutter 要重写外壳、引入 WebView 插件、包体明显变大，收益几乎为零，还要重新踩拍照 / 下载 / Cookie 的坑。
- 【AI建议：若将来确定要做 iOS 版且不愿维护两套，再评估 Flutter / RN；目前按纯 Android 推进，iOS 不在本轮范围】

---

## 六、已确认的范围

你已确认：**V2 与 V3 中的全部项目都开发，只有「预置服务器地址」不需要**。据此：

- 已按第四节装好 JDK 17 + Android SDK Platform 35 / Build-Tools 35.0.0 / platform-tools；
- 已在 `/workspace/baogong-android/` 完成 V2 + V3 全部源码改造，并配置 release 签名；
- 改动清单、打包步骤、服务端契约与排错见《报工助手-v2.0-开发说明与打包指南.md》。

仍未确认、但不阻塞本次交付的两点：**是否需要 iOS 版**（本轮按纯 Android）、**目标部署是内网 HTTP 还是公网 HTTPS**（默认兼容内网 HTTP，App 内提供「仅允许 HTTPS」开关；若全量 HTTPS，把 `network_security_config.xml` 的 `cleartextTrafficPermitted` 改为 `false` 即可）。
