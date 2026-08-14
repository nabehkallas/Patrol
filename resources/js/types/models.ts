export type Currency = 'SYP' | 'TRY' | 'USD';

export type TransactionType =
    | 'fuel_sale'
    | 'fuel_delivery'
    | 'other_income'
    | 'expense'
    | 'currency_exchange';

export type UserRole = 'admin' | 'attendant';

export type DebtStatus = 'outstanding' | 'settled';

export type DebtDirection = 'receivable' | 'payable';

export type SadcopLedgerEntryType = 'opening' | 'deposit' | 'delivery';

export type UserSummary = {
    id: number;
    name: string;
};

export type ManagedUser = UserSummary & {
    email: string;
    role: UserRole | null;
    created_at?: string;
};

export type FuelType = {
    id: number;
    name: string;
    slug: string;
    profit_margin_percent: string | null;
};

export type FuelPriceSnapshot = {
    price_per_liter: string;
    currency: Currency;
} | null;

export type FuelTypeWithPrice = FuelType & {
    currentPrice: FuelPriceSnapshot;
};

export type FuelTypeWithStats = FuelType & {
    currentPrice: FuelPriceSnapshot;
    latestInventory: { date: string; quantity_liters: string } | null;
};

export type Tank = {
    id: number;
    fuel_type_id: number;
    name: string;
    capacity_liters: string;
    fuel_type?: { id: number; name: string };
};

export type TankOption = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
    currentPrice: FuelPriceSnapshot;
    remaining_liters: number;
};

export type SadcopTankOption = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
    remaining_liters: number;
    default_cost_price_per_liter: number;
};

export type TankSummary = {
    id: number;
    name: string;
    capacity_liters: string;
    fuel_type: { id: number; name: string };
    expected_liters: number;
    latest_reading: { date: string; quantity_liters: string } | null;
    variance_liters: number | null;
};

export type Transaction = {
    id: number;
    type: TransactionType;
    fuel_type_id: number | null;
    tank_id: number | null;
    pump_id: number | null;
    liters: string | null;
    price_per_liter: string | null;
    description: string | null;
    amount: string;
    currency: Currency;
    to_currency: Currency | null;
    to_amount: string | null;
    exchange_rate_to_usd: string | null;
    occurred_at: string;
    notes: string | null;
    user?: UserSummary;
    fuel_type?: FuelType | null;
    tank?: Tank | null;
    pump?: { id: number; name: string } | null;
    debt?: {
        debtor_id?: number;
        direction?: DebtDirection;
        debtor?: { name: string };
    } | null;
};

export type FuelPrice = {
    id: number;
    fuel_type_id: number;
    price_per_liter: string;
    currency: Currency;
    effective_at: string;
    fuel_type?: FuelType;
    set_by?: UserSummary;
};

export type ExchangeRate = {
    id: number;
    currency: Currency;
    rate_to_usd: string;
    effective_at: string;
    set_by?: UserSummary;
};

export type InventoryEntry = {
    id: number;
    tank_id: number;
    date: string;
    quantity_liters: string;
    notes: string | null;
    tank?: Tank;
    recorded_by?: UserSummary;
};

export type Debtor = {
    id: number;
    name: string;
    phone: string | null;
    parent_id: number | null;
};

export type DebtorSummary = Debtor & {
    outstanding: CurrencyBreakdown;
    parent_name?: string | null;
    children?: DebtorSummary[];
};

export type Debt = {
    id: number;
    direction: DebtDirection;
    debtor_id: number;
    fuel_type_id: number | null;
    liters: string | null;
    price_per_liter: string | null;
    amount: string;
    currency: Currency;
    exchange_rate_to_usd: string | null;
    date: string;
    details: string | null;
    status: DebtStatus;
    remaining_amount: number;
    paid_amount: number;
    recorded_by?: UserSummary;
    debtor?: Debtor;
    fuel_type?: FuelType | null;
    transaction?: Transaction | null;
};

export type SadcopLedgerEntry = {
    id: number;
    type: SadcopLedgerEntryType;
    amount: string;
    liters: string | null;
    price_per_liter: string | null;
    occurred_at: string;
    notes: string | null;
    transaction?: Transaction | null;
    recorded_by?: UserSummary;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
};

export type FuelTypeLiters = {
    name: string;
    liters: number;
};

export type TransactionTotals = {
    income_syp: number;
    expense_syp: number;
    net_syp: number;
    liters_sold: number;
    liters_delivered: number;
    liters_sold_by_fuel_type: FuelTypeLiters[];
    debts_syp: number;
    debts_liters_sold: number;
};

export type CurrencyBreakdown = Partial<Record<Currency, number>> & {
    SYP: number;
};

export type DebtsSummary = {
    outstanding: CurrencyBreakdown;
    total: CurrencyBreakdown;
    payable_outstanding: CurrencyBreakdown;
    payable_total: CurrencyBreakdown;
};

export type CashBoxSummary = {
    income: CurrencyBreakdown;
    sadcop_expense_syp: number;
    other_expense: CurrencyBreakdown;
    exchanged: Partial<Record<Currency, number>>;
    net: CurrencyBreakdown;
    liters_sold: number;
    debts: CurrencyBreakdown;
    debts_liters_sold: number;
};

export type CashBox = {
    period: CashBoxSummary;
    today: CashBoxSummary;
};

export type CashBoxHistoryEntryType =
    | 'income'
    | 'expense'
    | 'sadcop'
    | 'exchange';

export type CashBoxHistoryEntry = {
    id: string;
    date: string;
    type: CashBoxHistoryEntryType;
    description: string;
    amount: number;
    currency: Currency;
};

export type EarningsBreakdownRow = {
    fuel_type: { id: number; name: string };
    liters_sold: number;
    profit_margin_percent: number;
    profit_margin_syp: number;
    margin_earnings_syp: number;
    topup_liters: number;
    price_per_liter_syp: number;
    topup_earnings_syp: number;
    subtotal_syp: number;
};

export type SalesChartPoint = { date: string } & Record<
    string,
    number | string
>;

export type SalesChartData = {
    fuelTypes: string[];
    data: SalesChartPoint[];
};

export type FuelPump = {
    id: number;
    name: string;
    fuel_type_ids: number[];
    fuel_type_names: string[];
};

export type PumpSummary = {
    id: number;
    name: string;
    fuel_type_ids: number[];
    fuel_type_names: string[];
    daily_liters_sold: number;
    latest_reading: {
        date: string;
        reading_value: number;
        tank_id: number | null;
    } | null;
};

export type PumpCounterReading = {
    id: number;
    pump_id: number;
    tank_id: number | null;
    date: string;
    created_at: string;
    reading_value: number;
    liters_sold: string | null;
    governmental_liters: string | null;
    return_liters: string | null;
    transaction_id: number | null;
    notes: string | null;
    pump?: { name: string };
    tank?: { name: string; fuel_type?: { name: string } };
    recorded_by?: UserSummary;
};

export type TankTopUp = {
    id: number;
    tank_id: number;
    liters: string;
    date: string;
    notes: string | null;
    tank?: Tank;
    recorded_by?: UserSummary;
};

export type TankTransfer = {
    id: number;
    from_tank_id: number;
    to_tank_id: number;
    liters: string;
    date: string;
    notes: string | null;
    from_tank?: Tank;
    to_tank?: Tank;
    recorded_by?: UserSummary;
};
