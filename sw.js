/**
 * Service Worker for RUIYI Restaurant POS PWA
 * Optimized caching strategies, image caching, and background sync
 * @version 2.0.0
 */

// ==================== 缓存配置 ====================
// 🔥 v72: 轻量 CDN 缓存（仅 CORS 资源，跳过 Tailwind 避免 opaque 7MB 惩罚）
const CACHE_VERSION = 'v120';
const STATIC_CACHE = `ruiyi-pos-static-${CACHE_VERSION}`;
const RUNTIME_CACHE = `ruiyi-pos-runtime-${CACHE_VERSION}`;
const IMAGE_CACHE = `ruiyi-pos-images-${CACHE_VERSION}`;
const API_CACHE = `ruiyi-pos-api-${CACHE_VERSION}`;
const POS_SHELL_CACHE = `ruiyi-pos-shell-${CACHE_VERSION}`;

// 缓存大小限制
const MAX_RUNTIME_CACHE_SIZE = 100;
const MAX_IMAGE_CACHE_SIZE = 200;
const MAX_API_CACHE_SIZE = 50;

// 缓存过期时间（毫秒）
const API_CACHE_DURATION = 5 * 60 * 1000; // 5分钟
const IMAGE_CACHE_DURATION = 7 * 24 * 60 * 60 * 1000; // 7天

// 主题目录路径
const THEME_PATH = '/wp-content/themes/RUIYI_RESTAURANT_POS-PWA-Test';
const OFFLINE_PAGE = `${THEME_PATH}/offline.html`;
const POS_START_PATHS = [
  '/usuario-pos/',
  '/usuario-pos',
  '/pos/',
  '/pos'
];
const POS_LAUNCH_ALIAS_PATHS = [
  '/',
  '/index.php'
];
const POS_OFFLINE_FALLBACK_PATHS = [
  ...POS_LAUNCH_ALIAS_PATHS,
  ...POS_START_PATHS
];
const POS_CANONICAL_URL = '/usuario-pos/';

// ==================== 预缓存资源 ====================
const STATIC_ASSETS = [
  `${THEME_PATH}/offline.html`,
  `${THEME_PATH}/manifest.json`,
  // JS模块
  `${THEME_PATH}/assets/js/modules/pos-cart.js`,
  `${THEME_PATH}/assets/js/modules/pos-payment.js`,
  `${THEME_PATH}/assets/js/modules/pos-tables.js`,
  `${THEME_PATH}/assets/js/modules/pos-print.js`,
  `${THEME_PATH}/assets/js/modules/pos-products.js`,
  `${THEME_PATH}/assets/js/modules/pos-offline.js`,
  `${THEME_PATH}/assets/js/modules/pos-split-billing.js`,
  `${THEME_PATH}/assets/js/modules/pos-history.js`,
  `${THEME_PATH}/assets/js/modules/pos-ui.js`,
  // 🔥 从 page-pos.php 提取的独立模块
  `${THEME_PATH}/js/modules/pos-table-resolver.js`,
  `${THEME_PATH}/js/modules/pos-kitchen-print.js`,
  // 🔥 CLODOP 打印和钱箱相关（支持离线打开钱箱）
  `${THEME_PATH}/clodop-integration-fixed.js`,
  '/LodopFuncs.js',  // 从网站根目录加载的 CLODOP 核心库
  // 图标
  `${THEME_PATH}/assets/icons/icon-192x192.png`,
  `${THEME_PATH}/assets/icons/icon-512x512.png`
];

// 🔥 CDN 资源（仅支持 CORS 的，跳过 cdn.tailwindcss.com 避免 opaque 7MB 惩罚）
// 总计 ~250KB，安全不会超配额
const CDN_ASSETS = [
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
  'https://cdn.jsdelivr.net/npm/sweetalert2@11',
  'https://unpkg.com/@popperjs/core@2.11.8/dist/umd/popper.min.js',
  'https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-solid-900.woff2',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-regular-400.woff2'
];

// CDN 域名（用于 cache-first 策略，不含 cdn.tailwindcss.com）
const CDN_HOSTS = [
  'cdnjs.cloudflare.com',
  'cdn.jsdelivr.net',
  'unpkg.com',
  'fonts.googleapis.com',
  'fonts.gstatic.com'
];

// ==================== 安装事件 ====================
self.addEventListener('install', (event) => {
  console.log('[SW] 🔧 Installing service worker...');

  event.waitUntil(
    Promise.all([
      // 缓存本地静态资源
      caches.open(STATIC_CACHE)
        .then((cache) => {
          console.log('[SW] 📦 Caching static assets');
          return cache.addAll(STATIC_ASSETS).catch((err) => {
            console.warn('[SW] ⚠️ Failed to cache some static assets:', err);
          });
        }),
      // 缓存 CDN 资源（逐个缓存，单个失败不影响其他）
      caches.open(STATIC_CACHE)
        .then((cache) => {
          return Promise.all(
            CDN_ASSETS.map(url =>
              fetch(url, { mode: 'cors' })
                .then(response => {
                  if (response.ok) {
                    cache.put(url, response);
                  }
                })
                .catch(() => {
                  // CDN 不可用时静默跳过，不阻塞安装
                })
            )
          );
        })
    ])
    .then(() => {
      console.log('[SW] ✅ All assets cached');
      return self.skipWaiting();
    })
  );
});

// ==================== 激活事件 ====================
self.addEventListener('activate', (event) => {
  console.log('[SW] ⚡ Activating service worker...');

  const currentCaches = [STATIC_CACHE, RUNTIME_CACHE, IMAGE_CACHE, API_CACHE, POS_SHELL_CACHE];

  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames
            .filter((cacheName) => !currentCaches.includes(cacheName))
            .map((cacheName) => {
              console.log('[SW] 🗑️ Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            })
        );
      })
      .then(() => {
        console.log('[SW] ✅ Old caches cleaned');
        return self.clients.claim();
      })
  );
});

// ==================== 请求拦截 ====================
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // 只处理GET请求
  if (request.method !== 'GET') {
    return;
  }

  // 跳过扩展和非HTTP请求
  if (!url.protocol.startsWith('http')) {
    return;
  }

  // 根据请求类型选择缓存策略
  if (isAPIRequest(url)) {
    event.respondWith(networkFirstWithCache(request, API_CACHE));
  } else if (isImageRequest(request, url)) {
    event.respondWith(cacheFirstWithNetwork(request, IMAGE_CACHE, MAX_IMAGE_CACHE_SIZE));
  } else if (isStaticAsset(url)) {
    event.respondWith(cacheFirstWithNetwork(request, STATIC_CACHE));
  } else if (isNavigationRequest(request)) {
    event.respondWith(networkFirstWithOffline(request));
  } else {
    event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE, MAX_RUNTIME_CACHE_SIZE));
  }
});

// ==================== 请求类型判断 ====================

function isAPIRequest(url) {
  return url.pathname.includes('/wp-admin/admin-ajax.php') ||
         url.pathname.includes('/wp-json/');
}

function isImageRequest(request, url) {
  const acceptHeader = request.headers.get('accept') || '';
  const isImageAccept = acceptHeader.includes('image');
  const isImagePath = /\.(jpg|jpeg|png|gif|webp|svg|ico)(\?.*)?$/i.test(url.pathname);
  const isUploadPath = url.pathname.includes('/uploads/');

  return isImageAccept || isImagePath || isUploadPath;
}

function isStaticAsset(url) {
  return /\.(js|css|woff|woff2|ttf|eot)(\?.*)?$/i.test(url.pathname) ||
         url.pathname.includes(THEME_PATH) ||
         CDN_HOSTS.includes(url.hostname);
}

function isNavigationRequest(request) {
  return request.mode === 'navigate' ||
         (request.headers.get('accept') || '').includes('text/html');
}

function isPOSNavigation(url) {
  return POS_START_PATHS.includes(url.pathname);
}

function shouldUsePOSShellFallback(url) {
  return POS_OFFLINE_FALLBACK_PATHS.includes(url.pathname);
}

function sameOriginUrl(pathOrUrl) {
  try {
    return new URL(pathOrUrl, self.location.origin).toString();
  } catch (error) {
    return new URL(POS_CANONICAL_URL, self.location.origin).toString();
  }
}

async function cachePOSNavigation(request, response) {
  if (!response || !response.ok) {
    return;
  }

  try {
    const cache = await caches.open(POS_SHELL_CACHE);
    const aliasRequests = [
      request,
      new Request(sameOriginUrl(POS_CANONICAL_URL), { credentials: 'include' }),
      ...POS_LAUNCH_ALIAS_PATHS.map((path) =>
        new Request(sameOriginUrl(path), { credentials: 'include' })
      )
    ];

    await Promise.all(
      aliasRequests.map((aliasRequest) => cache.put(aliasRequest, response.clone()))
    );

    console.log('[SW] POS shell cached:', request.url);
  } catch (error) {
    console.warn('[SW] Failed to cache POS shell:', error);
  }
}

async function findCachedPOSShell(request) {
  const cache = await caches.open(POS_SHELL_CACHE);
  const requestUrl = new URL(request.url);
  const candidates = [
    request,
    new Request(sameOriginUrl(requestUrl.pathname), { credentials: 'include' }),
    new Request(sameOriginUrl(POS_CANONICAL_URL), { credentials: 'include' }),
    ...POS_LAUNCH_ALIAS_PATHS.map((path) =>
      new Request(sameOriginUrl(path), { credentials: 'include' })
    )
  ];

  for (const candidate of candidates) {
    const cached = await cache.match(candidate, { ignoreSearch: true });
    if (cached) {
      return cached;
    }
  }

  const runtimeCached = await caches.match(request, { ignoreSearch: true });
  if (runtimeCached) {
    return runtimeCached;
  }

  return null;
}

// ==================== 缓存策略 ====================

/**
 * 网络优先，失败时使用缓存
 * 适用于API请求
 */
async function networkFirstWithCache(request, cacheName) {
  try {
    const response = await fetch(request);

    if (response.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, response.clone());

      // 限制缓存大小
      limitCacheSize(cacheName, MAX_API_CACHE_SIZE);
    }

    return response;
  } catch (error) {
    console.log('[SW] 📴 Network failed, trying cache:', request.url);
    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
      return cachedResponse;
    }

    // 返回错误响应
    return new Response(JSON.stringify({
      success: false,
      offline: true,
      message: 'Network unavailable'
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

/**
 * 缓存优先，缓存未命中时请求网络
 * 适用于图片和静态资源
 */
async function cacheFirstWithNetwork(request, cacheName, maxSize = null) {
  const cachedResponse = await caches.match(request);

  if (cachedResponse) {
    return cachedResponse;
  }

  try {
    const response = await fetch(request);

    if (response.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, response.clone());

      if (maxSize) {
        limitCacheSize(cacheName, maxSize);
      }
    }

    return response;
  } catch (error) {
    console.log('[SW] 📴 Failed to fetch:', request.url);

    // 返回占位图片（如果是图片请求）
    if (isImageRequest(request, new URL(request.url))) {
      return createPlaceholderImage();
    }

    return new Response('Offline', { status: 503 });
  }
}

/**
 * 网络优先，失败时显示离线页面
 * 适用于HTML页面导航
 */
async function networkFirstWithOffline(request) {
  const url = new URL(request.url);

  try {
    const response = await fetch(request);

    if (response.ok) {
      const cache = await caches.open(RUNTIME_CACHE);
      cache.put(request, response.clone());

      if (isPOSNavigation(url)) {
        await cachePOSNavigation(request, response.clone());
      }
    }

    return response;
  } catch (error) {
    console.log('[SW] 📴 Navigation failed, showing offline page');

    if (shouldUsePOSShellFallback(url)) {
      const posShell = await findCachedPOSShell(request);
      if (posShell) {
        console.log('[SW] 📴 Serving cached POS shell:', request.url);
        return posShell;
      }
    }

    // 尝试从缓存获取
    const cachedResponse = await caches.match(request, { ignoreSearch: true });
    if (cachedResponse) {
      return cachedResponse;
    }

    // 返回离线页面
    const offlinePage = await caches.match(OFFLINE_PAGE);
    if (offlinePage) {
      return offlinePage;
    }

    return new Response('Offline', {
      status: 503,
      headers: { 'Content-Type': 'text/html' }
    });
  }
}

/**
 * Stale-While-Revalidate策略
 * 先返回缓存，同时在后台更新
 */
async function staleWhileRevalidate(request, cacheName, maxSize = null) {
  const cache = await caches.open(cacheName);
  const cachedResponse = await cache.match(request);

  // 后台更新
  const fetchPromise = fetch(request)
    .then((response) => {
      if (response.ok) {
        cache.put(request, response.clone());
        if (maxSize) {
          limitCacheSize(cacheName, maxSize);
        }
      }
      return response;
    })
    .catch(() => null);

  // 如果有缓存，立即返回
  if (cachedResponse) {
    return cachedResponse;
  }

  // 没有缓存，等待网络
  const networkResponse = await fetchPromise;
  if (networkResponse) {
    return networkResponse;
  }

  return new Response('Offline', { status: 503 });
}

// ==================== 缓存管理 ====================

/**
 * 限制缓存大小
 */
async function limitCacheSize(cacheName, maxSize) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();

  if (keys.length > maxSize) {
    // 删除最旧的条目
    const keysToDelete = keys.slice(0, keys.length - maxSize);
    await Promise.all(keysToDelete.map(key => cache.delete(key)));
    console.log(`[SW] 🗑️ Cache ${cacheName} trimmed to ${maxSize} entries`);
  }
}

/**
 * 创建占位图片
 */
function createPlaceholderImage() {
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
      <rect width="200" height="200" fill="#f3f4f6"/>
      <text x="100" y="100" text-anchor="middle" fill="#9ca3af" font-family="sans-serif" font-size="14">
        Offline
      </text>
    </svg>
  `;

  return new Response(svg, {
    headers: { 'Content-Type': 'image/svg+xml' }
  });
}

// ==================== 后台同步 ====================

self.addEventListener('sync', (event) => {
  console.log('[SW] 🔄 Background sync triggered:', event.tag);

  if (event.tag === 'sync-orders') {
    event.waitUntil(syncOrders());
  }

  if (event.tag === 'sync-table-orders') {
    event.waitUntil(syncTableOrders());
  }
});

/**
 * 同步离线订单
 */
async function syncOrders() {
  try {
    console.log('[SW] 🔄 Background sync started');

    const db = await openDB();
    const pendingOrders = await getAllPendingOrders(db);

    if (pendingOrders.length === 0) {
      console.log('[SW] ✅ No pending orders to sync');
      return;
    }

    console.log(`[SW] 📤 Syncing ${pendingOrders.length} pending orders...`);

    const ajaxUrl = '/wp-admin/admin-ajax.php';
    const syncedOrders = [];
    const failedOrders = [];

    for (const order of pendingOrders) {
      try {
        const formData = buildOrderFormData(order);

        const response = await fetch(ajaxUrl, {
          method: 'POST',
          body: formData,
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (result.success) {
          await deletePendingOrder(db, order.id);
          syncedOrders.push(order.id);
          console.log(`[SW] ✅ Order ${order.id} synced successfully`);

          // 通知客户端
          await notifyClients({
            type: 'ORDER_SYNCED',
            orderId: result.data?.order_id || order.id,
            offlineOrderId: order.id,
          });
        } else {
          failedOrders.push(order.id);
          console.error(`[SW] ❌ Failed to sync order ${order.id}:`, result.message);
        }
      } catch (error) {
        failedOrders.push(order.id);
        console.error(`[SW] ❌ Error syncing order ${order.id}:`, error);
      }
    }

    console.log(`[SW] ✅ Background sync complete: ${syncedOrders.length} synced, ${failedOrders.length} failed`);

    // 通知客户端同步完成
    await notifyClients({
      type: 'SYNC_COMPLETE',
      synced: syncedOrders.length,
      failed: failedOrders.length,
    });
  } catch (error) {
    console.error('[SW] ❌ Error in syncOrders:', error);
  }
}

/**
 * 同步桌位订单
 */
async function syncTableOrders() {
  try {
    console.log('[SW] 🔄 Syncing table orders...');

    // 通知客户端执行桌位同步
    await notifyClients({
      type: 'SYNC_TABLE_ORDERS'
    });
  } catch (error) {
    console.error('[SW] ❌ Error syncing table orders:', error);
  }
}

// ==================== IndexedDB 辅助函数 ====================

function openDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('RUIYI_POS_DB', 3);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      db.onversionchange = () => db.close();
      resolve(db);
    };

    request.onupgradeneeded = (event) => {
      const db = event.target.result;

      if (!db.objectStoreNames.contains('pending_orders')) {
        const store = db.createObjectStore('pending_orders', {
          keyPath: 'id',
          autoIncrement: false
        });
        store.createIndex('timestamp', 'timestamp', { unique: false });
        store.createIndex('status', 'status', { unique: false });
      }

      if (!db.objectStoreNames.contains('products')) {
        const productsStore = db.createObjectStore('products', { keyPath: 'id' });
        productsStore.createIndex('category', 'category', { unique: false });
        productsStore.createIndex('name', 'name', { unique: false });
      }

      if (!db.objectStoreNames.contains('categories')) {
        db.createObjectStore('categories', { keyPath: 'id' });
      }

      if (!db.objectStoreNames.contains('customers')) {
        const customersStore = db.createObjectStore('customers', { keyPath: 'id' });
        customersStore.createIndex('email', 'email', { unique: false });
      }

      if (!db.objectStoreNames.contains('tables')) {
        const tablesStore = db.createObjectStore('tables', { keyPath: 'tableId' });
        tablesStore.createIndex('status', 'status', { unique: false });
      }

      if (!db.objectStoreNames.contains('settings')) {
        db.createObjectStore('settings', { keyPath: 'key' });
      }

      if (!db.objectStoreNames.contains('sync_queue')) {
        const syncStore = db.createObjectStore('sync_queue', {
          keyPath: 'id',
          autoIncrement: true
        });
        syncStore.createIndex('type', 'type', { unique: false });
        syncStore.createIndex('timestamp', 'timestamp', { unique: false });
      }

      if (!db.objectStoreNames.contains('pending_payments')) {
        const paymentStore = db.createObjectStore('pending_payments', {
          keyPath: 'id',
          autoIncrement: false
        });
        paymentStore.createIndex('timestamp', 'timestamp', { unique: false });
        paymentStore.createIndex('status', 'status', { unique: false });
        paymentStore.createIndex('type', 'type', { unique: false });
      }
    };
  });
}

function getAllPendingOrders(db) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(['pending_orders'], 'readonly');
    const store = tx.objectStore('pending_orders');
    const getAllReq = store.getAll();

    getAllReq.onsuccess = () => resolve(getAllReq.result || []);
    getAllReq.onerror = () => reject(getAllReq.error);
  });
}

function deletePendingOrder(db, orderId) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(['pending_orders'], 'readwrite');
    const store = tx.objectStore('pending_orders');
    const deleteReq = store.delete(orderId);

    deleteReq.onsuccess = () => resolve();
    deleteReq.onerror = () => reject(deleteReq.error);
  });
}

// ==================== 辅助函数 ====================

function buildOrderFormData(order) {
  const formData = new FormData();
  formData.append('action', 'ruiyi_pos_create_order');
  formData.append('nonce', order.nonce || '');
  formData.append('cart', JSON.stringify(order.cart || []));
  formData.append('discount', order.discount || 0);
  formData.append('table_number', order.table_number || '');
  formData.append('client', order.client || '');
  formData.append('order_type', order.order_type || 'llevar');
  formData.append('payment_method', order.payment_method || 'cash');
  formData.append('offline_order', '1');

  if (order.customer_info) {
    formData.append('customer_info', JSON.stringify(order.customer_info));
  }
  if (order.received_amount !== undefined) {
    formData.append('received_amount', order.received_amount);
  }
  if (order.change_amount !== undefined) {
    formData.append('change_amount', order.change_amount);
  }
  if (order.cash_amount !== undefined) {
    formData.append('cash_amount', order.cash_amount);
  }
  if (order.card_amount !== undefined) {
    formData.append('card_amount', order.card_amount);
  }
  if (order.tip_amount !== undefined) {
    formData.append('tip_amount', order.tip_amount);
  }
  if (order.is_addition !== undefined) {
    formData.append('is_addition', order.is_addition);
  }

  return formData;
}

async function notifyClients(message) {
  const clients = await self.clients.matchAll();
  clients.forEach((client) => {
    client.postMessage(message);
  });
}

// ==================== 消息处理 ====================

self.addEventListener('message', (event) => {
  const { data } = event;

  if (!data || !data.type) return;

  switch (data.type) {
    case 'SKIP_WAITING':
      self.skipWaiting();
      break;

    case 'SYNC_ORDERS':
      syncOrders();
      break;

    case 'SYNC_TABLE_ORDERS':
      syncTableOrders();
      break;

    case 'CLEAR_CACHE':
      clearAllCaches();
      break;

    case 'CACHE_IMAGE':
      if (data.url) {
        cacheImage(data.url);
      }
      break;

    case 'CACHE_POS_SHELL':
      if (data.url) {
        event.waitUntil(cachePOSShellFromUrl(data.url));
      }
      break;
  }
});

async function cachePOSShellFromUrl(url) {
  try {
    const request = new Request(sameOriginUrl(url), {
      credentials: 'include',
      cache: 'reload'
    });
    const response = await fetch(request);

    if (response.ok) {
      await cachePOSNavigation(request, response);
    }
  } catch (error) {
    console.warn('[SW] Failed to warm POS shell cache:', error);
  }
}

/**
 * 清除所有缓存
 */
async function clearAllCaches() {
  const cacheNames = await caches.keys();
  await Promise.all(cacheNames.map(name => caches.delete(name)));
  console.log('[SW] 🗑️ All caches cleared');
}

/**
 * 缓存单张图片
 */
async function cacheImage(url) {
  try {
    const cache = await caches.open(IMAGE_CACHE);
    const response = await fetch(url);
    if (response.ok) {
      await cache.put(url, response);
      console.log('[SW] 📷 Image cached:', url);
    }
  } catch (error) {
    console.error('[SW] ❌ Failed to cache image:', error);
  }
}

// ==================== 周期性同步（如支持）====================

self.addEventListener('periodicsync', (event) => {
  if (event.tag === 'sync-pending-orders') {
    event.waitUntil(syncOrders());
  }
});
