<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { Card } from "@/components/ui/card";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Loader2,
  Users,
  Save,
  Edit,
  ChevronLeft,
  ChevronRight,
  X,
  Search,
  BarChart3,
  Settings,
  HardDrive,
  Cpu,
  Database,
  Server,
  Archive,
  Network,
  ExternalLink,
} from "lucide-vue-next";
import {
  useResourcesAdminAPI,
  type UserResource,
  type ResourceStatistics,
  type ResourceSettings,
} from "@/composables/useResourcesAdminAPI";
import { useToast } from "vue-toastification";

const toast = useToast();
const {
  getUsers,
  getUserResources,
  updateUserResources,
  searchUsers,
  getStatistics,
  getSettings,
  updateSettings,
} = useResourcesAdminAPI();

// Users list
const users = ref<UserResource[]>([]);
const usersPage = ref(1);
const usersTotal = ref(0);
const usersPerPage = ref(20);
const searchQuery = ref("");
const loadingUsers = ref(false);

// Statistics
const statistics = ref<ResourceStatistics | null>(null);
const loadingStats = ref(false);

// Settings
const settings = ref<ResourceSettings | null>(null);
const loadingSettings = ref(false);
const savingSettings = ref(false);
const settingsForm = ref({
  default_resources: {
    memory_limit: 0,
    cpu_limit: 0,
    disk_limit: 0,
    server_limit: 0,
    database_limit: 0,
    backup_limit: 0,
    allocation_limit: 0,
  },
  max_resources: {
    memory_limit: 0,
    cpu_limit: 0,
    disk_limit: 0,
    server_limit: 0,
    database_limit: 0,
    backup_limit: 0,
    allocation_limit: 0,
  },
});

// Resource edit form
const showResourceForm = ref(false);
const editingUser = ref<UserResource | null>(null);
const resourceForm = ref({
  memory_limit: 0,
  cpu_limit: 0,
  disk_limit: 0,
  server_limit: 0,
  database_limit: 0,
  backup_limit: 0,
  allocation_limit: 0,
});
const savingResources = ref(false);

// User search for resource editing
const showUserSearch = ref(false);
const userSearchQuery = ref("");
const userSearchResults = ref<UserResource[]>([]);
const searchingUsers = ref(false);
const userSearchDebounce = ref<number | null>(null);

// Active tab
const activeTab = ref("users");

// Watch for tab changes
watch(activeTab, (newTab) => {
  if (newTab === "users" && users.value.length === 0) {
    loadUsers();
  } else if (newTab === "statistics" && !statistics.value) {
    loadStatistics();
  } else if (newTab === "settings" && !settings.value) {
    loadSettings();
  }
});

const loadUsers = async (page: number = 1) => {
  usersPage.value = page;
  loadingUsers.value = true;
  try {
    const result = await getUsers(page, usersPerPage.value, searchQuery.value);
    users.value = result.data;
    usersTotal.value = result.meta.pagination.total;
  } catch (err) {
    toast.error(err instanceof Error ? err.message : "Failed to load users");
  } finally {
    loadingUsers.value = false;
  }
};

const loadStatistics = async () => {
  loadingStats.value = true;
  try {
    statistics.value = await getStatistics();
  } catch (err) {
    toast.error(
      err instanceof Error ? err.message : "Failed to load statistics"
    );
  } finally {
    loadingStats.value = false;
  }
};

const openResourceForm = async (user: UserResource) => {
  editingUser.value = user;
  try {
    const userResources = await getUserResources(user.id);
    resourceForm.value = {
      memory_limit: userResources.resources.memory_limit || 0,
      cpu_limit: userResources.resources.cpu_limit || 0,
      disk_limit: userResources.resources.disk_limit || 0,
      server_limit: userResources.resources.server_limit || 0,
      database_limit: userResources.resources.database_limit || 0,
      backup_limit: userResources.resources.backup_limit || 0,
      allocation_limit: userResources.resources.allocation_limit || 0,
    };
    showResourceForm.value = true;
  } catch (err) {
    toast.error(
      err instanceof Error ? err.message : "Failed to load user resources"
    );
  }
};

const closeResourceForm = () => {
  showResourceForm.value = false;
  editingUser.value = null;
  resourceForm.value = {
    memory_limit: 0,
    cpu_limit: 0,
    disk_limit: 0,
    server_limit: 0,
    database_limit: 0,
    backup_limit: 0,
    allocation_limit: 0,
  };
};

const saveResources = async () => {
  if (!editingUser.value) return;

  // Validate all values are non-negative
  for (const [key, value] of Object.entries(resourceForm.value)) {
    if (value < 0) {
      toast.error(`${key.replace("_", " ")} must be non-negative`);
      return;
    }
  }

  savingResources.value = true;
  try {
    await updateUserResources(editingUser.value.id, resourceForm.value);
    toast.success("Resources updated successfully!");
    closeResourceForm();
    await loadUsers(usersPage.value);
  } catch (err) {
    toast.error(
      err instanceof Error ? err.message : "Failed to update resources"
    );
  } finally {
    savingResources.value = false;
  }
};

const handleSearch = () => {
  loadUsers(1);
};

const performUserSearch = async () => {
  if (userSearchQuery.value.length < 2) {
    userSearchResults.value = [];
    return;
  }

  searchingUsers.value = true;
  try {
    const results = await searchUsers(userSearchQuery.value, 20);
    userSearchResults.value = results;
  } catch (err) {
    toast.error(err instanceof Error ? err.message : "Failed to search users");
    userSearchResults.value = [];
  } finally {
    searchingUsers.value = false;
  }
};

const handleUserSearchInput = () => {
  if (userSearchDebounce.value) {
    clearTimeout(userSearchDebounce.value);
  }
  userSearchDebounce.value = window.setTimeout(() => {
    performUserSearch();
  }, 300);
};

const selectUserFromSearch = async (user: UserResource) => {
  showUserSearch.value = false;
  userSearchQuery.value = "";
  userSearchResults.value = [];
  await openResourceForm(user);
};

const openUserSearch = () => {
  showUserSearch.value = true;
  userSearchQuery.value = "";
  userSearchResults.value = [];
};

const formatBytes = (mb: number): string => {
  if (mb === 0) return "0 MB";
  // Input is already in MB, not bytes
  if (mb >= 1024) {
    return `${(mb / 1024).toFixed(2)} GB`;
  }
  return `${mb.toFixed(2)} MB`;
};

const formatNumber = (num: number): string => {
  return new Intl.NumberFormat().format(num);
};

const userEditUrl = (uuid: string): string => {
  return `/admin/users/${uuid}/edit`;
};

const loadSettings = async () => {
  loadingSettings.value = true;
  try {
    settings.value = await getSettings();
    if (settings.value) {
      settingsForm.value = {
        default_resources: { ...settings.value.default_resources },
        max_resources: { ...settings.value.max_resources },
      };
    }
  } catch (err) {
    toast.error(err instanceof Error ? err.message : "Failed to load settings");
  } finally {
    loadingSettings.value = false;
  }
};

const saveSettings = async () => {
  savingSettings.value = true;
  try {
    settings.value = await updateSettings(settingsForm.value);
    toast.success("Settings saved successfully!");
  } catch (err) {
    toast.error(err instanceof Error ? err.message : "Failed to save settings");
  } finally {
    savingSettings.value = false;
  }
};

onMounted(() => {
  if (activeTab.value === "users") {
    loadUsers();
  } else if (activeTab.value === "statistics") {
    loadStatistics();
  } else if (activeTab.value === "settings") {
    loadSettings();
  }
});
</script>

<template>
  <div class="w-full h-full overflow-auto p-4">
    <div class="container mx-auto max-w-7xl">
      <div class="mb-6">
        <h1 class="text-2xl font-semibold">Resource Management - Admin</h1>
        <p class="text-sm text-muted-foreground">
          Manage user resource limits and view statistics
        </p>
      </div>

      <Tabs v-model="activeTab" class="w-full">
        <TabsList class="grid w-full grid-cols-3">
          <TabsTrigger value="users">
            <Users class="h-4 w-4 mr-2" />
            Users
          </TabsTrigger>
          <TabsTrigger value="statistics">
            <BarChart3 class="h-4 w-4 mr-2" />
            Statistics
          </TabsTrigger>
          <TabsTrigger value="settings">
            <Settings class="h-4 w-4 mr-2" />
            Settings
          </TabsTrigger>
        </TabsList>

        <TabsContent value="users" class="mt-4">
          <Card>
            <div class="p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold">User Resources</h3>
                <div class="flex gap-2">
                  <div class="flex gap-2">
                    <Input
                      v-model="searchQuery"
                      placeholder="Search users..."
                      class="w-64"
                      @keyup.enter="handleSearch"
                    />
                    <Button @click="handleSearch" variant="outline" size="sm">
                      <Search class="h-4 w-4" />
                    </Button>
                  </div>
                  <Button @click="openUserSearch" variant="default" size="sm">
                    <Edit class="h-4 w-4 mr-2" />
                    Edit Resources
                  </Button>
                  <Button
                    @click="loadUsers(usersPage)"
                    variant="outline"
                    size="sm"
                  >
                    Refresh
                  </Button>
                </div>
              </div>

              <div
                v-if="loadingUsers && users.length === 0"
                class="flex items-center justify-center py-12"
              >
                <Loader2 class="h-8 w-8 animate-spin" />
              </div>
              <div
                v-else-if="users.length === 0"
                class="text-center py-12 text-muted-foreground"
              >
                No users found.
              </div>
              <div v-else class="space-y-2">
                <div
                  v-for="user in users"
                  :key="user.id"
                  class="p-4 border rounded-lg hover:bg-accent transition-colors"
                >
                  <div class="flex items-center justify-between">
                    <div class="flex-1">
                      <div class="flex items-center gap-2 mb-3">
                        <h4 class="font-semibold">{{ user.username }}</h4>
                        <Badge variant="outline">{{ user.email }}</Badge>
                      </div>
                      <div
                        class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 text-sm"
                      >
                        <div>
                          <div
                            class="flex items-center gap-1 text-muted-foreground mb-1"
                          >
                            <HardDrive class="h-3 w-3" />
                            <span>Memory</span>
                          </div>
                          <span class="font-medium">
                            {{ formatBytes(user.memory_limit) }}
                          </span>
                        </div>
                        <div>
                          <div
                            class="flex items-center gap-1 text-muted-foreground mb-1"
                          >
                            <Cpu class="h-3 w-3" />
                            <span>CPU</span>
                          </div>
                          <span class="font-medium">
                            {{ user.cpu_limit }}%
                          </span>
                        </div>
                        <div>
                          <div
                            class="flex items-center gap-1 text-muted-foreground mb-1"
                          >
                            <Database class="h-3 w-3" />
                            <span>Disk</span>
                          </div>
                          <span class="font-medium">
                            {{ formatBytes(user.disk_limit) }}
                          </span>
                        </div>
                        <div>
                          <div
                            class="flex items-center gap-1 text-muted-foreground mb-1"
                          >
                            <Server class="h-3 w-3" />
                            <span>Servers</span>
                          </div>
                          <span class="font-medium">
                            {{ formatNumber(user.server_limit) }}
                          </span>
                        </div>
                        <div>
                          <div
                            class="flex items-center gap-1 text-muted-foreground mb-1"
                          >
                            <Database class="h-3 w-3" />
                            <span>Databases</span>
                          </div>
                          <span class="font-medium">
                            {{ formatNumber(user.database_limit) }}
                          </span>
                        </div>
                        <div>
                          <div
                            class="flex items-center gap-1 text-muted-foreground mb-1"
                          >
                            <Archive class="h-3 w-3" />
                            <span>Backups</span>
                          </div>
                          <span class="font-medium">
                            {{ formatNumber(user.backup_limit) }}
                          </span>
                        </div>
                        <div>
                          <div
                            class="flex items-center gap-1 text-muted-foreground mb-1"
                          >
                            <Network class="h-3 w-3" />
                            <span>Allocations</span>
                          </div>
                          <span class="font-medium">
                            {{ formatNumber(user.allocation_limit) }}
                          </span>
                        </div>
                      </div>
                    </div>
                    <div class="flex gap-2 ml-4">
                      <a
                        :href="userEditUrl(user.uuid)"
                        target="_top"
                        class="inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground h-9 px-3"
                      >
                        <ExternalLink class="h-4 w-4" />
                        View account
                      </a>
                      <Button
                        @click="openResourceForm(user)"
                        variant="outline"
                        size="sm"
                      >
                        <Edit class="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Pagination -->
              <div
                v-if="Math.ceil(usersTotal / usersPerPage) > 1"
                class="flex items-center justify-center gap-2 mt-6"
              >
                <Button
                  @click="loadUsers(usersPage - 1)"
                  :disabled="usersPage === 1"
                  variant="outline"
                  size="sm"
                >
                  <ChevronLeft class="h-4 w-4" />
                </Button>
                <span class="text-sm text-muted-foreground">
                  Page {{ usersPage }} of
                  {{ Math.ceil(usersTotal / usersPerPage) }} ({{ usersTotal }}
                  total)
                </span>
                <Button
                  @click="loadUsers(usersPage + 1)"
                  :disabled="usersPage >= Math.ceil(usersTotal / usersPerPage)"
                  variant="outline"
                  size="sm"
                >
                  <ChevronRight class="h-4 w-4" />
                </Button>
              </div>
            </div>
          </Card>
        </TabsContent>

        <TabsContent value="statistics" class="mt-4">
          <Card>
            <div class="p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold">Resource Statistics</h3>
                <Button @click="loadStatistics" variant="outline" size="sm">
                  Refresh
                </Button>
              </div>

              <div
                v-if="loadingStats && !statistics"
                class="flex items-center justify-center py-12"
              >
                <Loader2 class="h-8 w-8 animate-spin" />
              </div>
              <div v-else-if="statistics" class="space-y-6">
                <!-- User Statistics -->
                <div>
                  <h4 class="font-semibold mb-3">Users</h4>
                  <div class="grid grid-cols-3 gap-4">
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground">
                        Total Users
                      </div>
                      <div class="text-2xl font-bold">
                        {{ formatNumber(statistics.users.total) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground">
                        With Resources
                      </div>
                      <div class="text-2xl font-bold">
                        {{ formatNumber(statistics.users.with_resources) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground">
                        Without Resources
                      </div>
                      <div class="text-2xl font-bold">
                        {{ formatNumber(statistics.users.without_resources) }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Total Resources -->
                <div>
                  <h4 class="font-semibold mb-3">Total Resources</h4>
                  <div
                    class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4"
                  >
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Memory
                      </div>
                      <div class="text-lg font-semibold">
                        {{ formatBytes(statistics.totals.memory_limit) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">CPU</div>
                      <div class="text-lg font-semibold">
                        {{ formatNumber(statistics.totals.cpu_limit) }}%
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">Disk</div>
                      <div class="text-lg font-semibold">
                        {{ formatBytes(statistics.totals.disk_limit) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Servers
                      </div>
                      <div class="text-lg font-semibold">
                        {{ formatNumber(statistics.totals.server_limit) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Databases
                      </div>
                      <div class="text-lg font-semibold">
                        {{ formatNumber(statistics.totals.database_limit) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Backups
                      </div>
                      <div class="text-lg font-semibold">
                        {{ formatNumber(statistics.totals.backup_limit) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Allocations
                      </div>
                      <div class="text-lg font-semibold">
                        {{ formatNumber(statistics.totals.allocation_limit) }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Average Resources -->
                <div>
                  <h4 class="font-semibold mb-3">Average Resources per User</h4>
                  <div
                    class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4"
                  >
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Memory
                      </div>
                      <div class="text-lg font-semibold">
                        {{ formatBytes(statistics.averages.memory_limit) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">CPU</div>
                      <div class="text-lg font-semibold">
                        {{ statistics.averages.cpu_limit.toFixed(2) }}%
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">Disk</div>
                      <div class="text-lg font-semibold">
                        {{ formatBytes(statistics.averages.disk_limit) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Servers
                      </div>
                      <div class="text-lg font-semibold">
                        {{ statistics.averages.server_limit.toFixed(2) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Databases
                      </div>
                      <div class="text-lg font-semibold">
                        {{ statistics.averages.database_limit.toFixed(2) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Backups
                      </div>
                      <div class="text-lg font-semibold">
                        {{ statistics.averages.backup_limit.toFixed(2) }}
                      </div>
                    </div>
                    <div class="p-4 border rounded-lg">
                      <div class="text-sm text-muted-foreground mb-1">
                        Allocations
                      </div>
                      <div class="text-lg font-semibold">
                        {{ statistics.averages.allocation_limit.toFixed(2) }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </Card>
        </TabsContent>

        <TabsContent value="settings" class="mt-4">
          <Card>
            <div class="p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold">Resource Settings</h3>
                <Button @click="loadSettings" variant="outline" size="sm">
                  Refresh
                </Button>
              </div>

              <div
                v-if="loadingSettings && !settings"
                class="flex items-center justify-center py-12"
              >
                <Loader2 class="h-8 w-8 animate-spin" />
              </div>
              <form
                v-else-if="settings"
                @submit.prevent="saveSettings"
                class="space-y-6"
              >
                <!-- Default Resources -->
                <div>
                  <h4 class="font-semibold mb-3">Default Resources</h4>
                  <p class="text-sm text-muted-foreground mb-4">
                    Resources that new users will receive when their account is
                    created.
                  </p>
                  <div
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4"
                  >
                    <div>
                      <Label for="default_memory">Memory Limit (MB)</Label>
                      <Input
                        id="default_memory"
                        v-model.number="
                          settingsForm.default_resources.memory_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                    </div>
                    <div>
                      <Label for="default_cpu">CPU Limit (%)</Label>
                      <Input
                        id="default_cpu"
                        v-model.number="
                          settingsForm.default_resources.cpu_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                    </div>
                    <div>
                      <Label for="default_disk">Disk Limit (MB)</Label>
                      <Input
                        id="default_disk"
                        v-model.number="
                          settingsForm.default_resources.disk_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                    </div>
                    <div>
                      <Label for="default_servers">Server Limit</Label>
                      <Input
                        id="default_servers"
                        v-model.number="
                          settingsForm.default_resources.server_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                    </div>
                    <div>
                      <Label for="default_databases">Database Limit</Label>
                      <Input
                        id="default_databases"
                        v-model.number="
                          settingsForm.default_resources.database_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                    </div>
                    <div>
                      <Label for="default_backups">Backup Limit</Label>
                      <Input
                        id="default_backups"
                        v-model.number="
                          settingsForm.default_resources.backup_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                    </div>
                    <div>
                      <Label for="default_allocations">Allocation Limit</Label>
                      <Input
                        id="default_allocations"
                        v-model.number="
                          settingsForm.default_resources.allocation_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                    </div>
                  </div>
                </div>

                <!-- Max Resources -->
                <div>
                  <h4 class="font-semibold mb-3">Maximum Resources</h4>
                  <p class="text-sm text-muted-foreground mb-4">
                    Maximum resources users can have. Set to 0 for unlimited.
                  </p>
                  <div
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4"
                  >
                    <div>
                      <Label for="max_memory">Memory Limit (MB)</Label>
                      <Input
                        id="max_memory"
                        v-model.number="settingsForm.max_resources.memory_limit"
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                      <p class="text-xs text-muted-foreground mt-1">
                        0 = unlimited
                      </p>
                    </div>
                    <div>
                      <Label for="max_cpu">CPU Limit (%)</Label>
                      <Input
                        id="max_cpu"
                        v-model.number="settingsForm.max_resources.cpu_limit"
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                      <p class="text-xs text-muted-foreground mt-1">
                        0 = unlimited
                      </p>
                    </div>
                    <div>
                      <Label for="max_disk">Disk Limit (MB)</Label>
                      <Input
                        id="max_disk"
                        v-model.number="settingsForm.max_resources.disk_limit"
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                      <p class="text-xs text-muted-foreground mt-1">
                        0 = unlimited
                      </p>
                    </div>
                    <div>
                      <Label for="max_servers">Server Limit</Label>
                      <Input
                        id="max_servers"
                        v-model.number="settingsForm.max_resources.server_limit"
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                      <p class="text-xs text-muted-foreground mt-1">
                        0 = unlimited
                      </p>
                    </div>
                    <div>
                      <Label for="max_databases">Database Limit</Label>
                      <Input
                        id="max_databases"
                        v-model.number="
                          settingsForm.max_resources.database_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                      <p class="text-xs text-muted-foreground mt-1">
                        0 = unlimited
                      </p>
                    </div>
                    <div>
                      <Label for="max_backups">Backup Limit</Label>
                      <Input
                        id="max_backups"
                        v-model.number="settingsForm.max_resources.backup_limit"
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                      <p class="text-xs text-muted-foreground mt-1">
                        0 = unlimited
                      </p>
                    </div>
                    <div>
                      <Label for="max_allocations">Allocation Limit</Label>
                      <Input
                        id="max_allocations"
                        v-model.number="
                          settingsForm.max_resources.allocation_limit
                        "
                        type="number"
                        min="0"
                        class="mt-2"
                      />
                      <p class="text-xs text-muted-foreground mt-1">
                        0 = unlimited
                      </p>
                    </div>
                  </div>
                </div>

                <div class="flex justify-end pt-4 border-t">
                  <Button type="submit" :disabled="savingSettings">
                    <Loader2
                      v-if="savingSettings"
                      class="h-4 w-4 mr-2 animate-spin"
                    />
                    <Save v-else class="h-4 w-4 mr-2" />
                    Save Settings
                  </Button>
                </div>
              </form>
            </div>
          </Card>
        </TabsContent>
      </Tabs>

      <!-- Resource Edit Form Modal -->
      <div
        v-if="showResourceForm && editingUser"
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
        @click.self="closeResourceForm"
      >
        <Card class="w-full max-w-2xl m-4 max-h-[90vh] overflow-auto">
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-semibold">
                Edit Resources - {{ editingUser.username }}
              </h3>
              <div class="flex items-center gap-2">
                <a
                  :href="userEditUrl(editingUser.uuid)"
                  target="_top"
                  class="inline-flex items-center gap-2 rounded-md text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground h-9 px-3"
                >
                  <ExternalLink class="h-4 w-4" />
                  View panel account
                </a>
                <Button @click="closeResourceForm" variant="ghost" size="sm">
                  <X class="h-4 w-4" />
                </Button>
              </div>
            </div>

            <form @submit.prevent="saveResources" class="space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <Label for="memory_limit">Memory Limit (MB)</Label>
                  <Input
                    id="memory_limit"
                    v-model.number="resourceForm.memory_limit"
                    type="number"
                    min="0"
                    class="mt-2"
                  />
                </div>
                <div>
                  <Label for="cpu_limit">CPU Limit (%)</Label>
                  <Input
                    id="cpu_limit"
                    v-model.number="resourceForm.cpu_limit"
                    type="number"
                    min="0"
                    class="mt-2"
                  />
                </div>
                <div>
                  <Label for="disk_limit">Disk Limit (MB)</Label>
                  <Input
                    id="disk_limit"
                    v-model.number="resourceForm.disk_limit"
                    type="number"
                    min="0"
                    class="mt-2"
                  />
                </div>
                <div>
                  <Label for="server_limit">Server Limit</Label>
                  <Input
                    id="server_limit"
                    v-model.number="resourceForm.server_limit"
                    type="number"
                    min="0"
                    class="mt-2"
                  />
                </div>
                <div>
                  <Label for="database_limit">Database Limit</Label>
                  <Input
                    id="database_limit"
                    v-model.number="resourceForm.database_limit"
                    type="number"
                    min="0"
                    class="mt-2"
                  />
                </div>
                <div>
                  <Label for="backup_limit">Backup Limit</Label>
                  <Input
                    id="backup_limit"
                    v-model.number="resourceForm.backup_limit"
                    type="number"
                    min="0"
                    class="mt-2"
                  />
                </div>
                <div>
                  <Label for="allocation_limit">Allocation Limit</Label>
                  <Input
                    id="allocation_limit"
                    v-model.number="resourceForm.allocation_limit"
                    type="number"
                    min="0"
                    class="mt-2"
                  />
                </div>
              </div>

              <div class="flex justify-end gap-2 pt-4 border-t">
                <Button
                  type="button"
                  @click="closeResourceForm"
                  variant="outline"
                >
                  Cancel
                </Button>
                <Button type="submit" :disabled="savingResources">
                  <Loader2
                    v-if="savingResources"
                    class="h-4 w-4 mr-2 animate-spin"
                  />
                  <Save v-else class="h-4 w-4 mr-2" />
                  Save Resources
                </Button>
              </div>
            </form>
          </div>
        </Card>
      </div>

      <!-- User Search Dialog -->
      <Dialog :open="showUserSearch" @update:open="showUserSearch = $event">
        <DialogContent class="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Search User to Edit Resources</DialogTitle>
            <DialogDescription>
              Search for a user by username, email, or UUID to edit their
              resource limits
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-4 py-4">
            <div class="flex gap-2">
              <div class="relative flex-1">
                <Search
                  class="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground h-4 w-4"
                />
                <Input
                  v-model="userSearchQuery"
                  placeholder="Search by username, email, or UUID..."
                  class="pl-10"
                  @input="handleUserSearchInput"
                  @keyup.enter="performUserSearch"
                />
              </div>
              <Button
                @click="performUserSearch"
                :disabled="userSearchQuery.length < 2"
              >
                <Search class="h-4 w-4" />
              </Button>
            </div>

            <div
              v-if="searchingUsers"
              class="flex items-center justify-center py-8"
            >
              <Loader2 class="h-6 w-6 animate-spin" />
            </div>
            <div
              v-else-if="
                userSearchResults.length === 0 && userSearchQuery.length >= 2
              "
              class="text-center py-8 text-muted-foreground"
            >
              No users found. Try a different search term.
            </div>
            <div
              v-else-if="userSearchQuery.length < 2"
              class="text-center py-8 text-muted-foreground"
            >
              Enter at least 2 characters to search
            </div>
            <div v-else class="space-y-2 max-h-96 overflow-y-auto">
              <div
                v-for="user in userSearchResults"
                :key="user.id"
                @click="selectUserFromSearch(user)"
                class="flex items-center justify-between p-4 border rounded-lg hover:bg-accent cursor-pointer transition-colors"
              >
                <div class="flex-1">
                  <div class="font-semibold">{{ user.username }}</div>
                  <div class="text-sm text-muted-foreground">
                    {{ user.email }}
                  </div>
                  <div class="text-xs text-muted-foreground mt-1">
                    ID: {{ user.id }}
                  </div>
                </div>
                <Button variant="ghost" size="sm">
                  <Edit class="h-4 w-4" />
                </Button>
              </div>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  </div>
</template>
