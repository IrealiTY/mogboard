class XIVAPI
{
    get(endpoint, queries, callback) {

        queries = queries ? queries : {};
        queries.key = 'xiv_mogboard14_f1daf0d53';
        queries.tags = 'mogboard';

        let query = Object.keys(queries)
            .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(queries[k]))
            .join('&');

        endpoint = endpoint +'?'+ query;

        fetch (`https://xivapi.com${endpoint}`, { mode: 'cors' })
            .then(response => response.json())
            .then(data => callback(data))
    }

    /**
     * Search for an item
     */
    search(string, callback) {
        let params = {
            indexes: 'item',
            filters: 'ItemSearchCategory.ID>=1',
            columns: 'ID,Icon,Name,LevelItem,Rarity,ItemSearchCategory.Name,ItemSearchCategory.ID,ItemKind.Name',
            string:  string.trim(),
            limit:   50,
        };

        this.get(`/search`, params, callback);
    }

    /**
     * Return information about an item
     */
    getItem(itemId, callback) {
        this.get(`/Item/${itemId}`, {}, callback);
    }

    /**
     * Get the current prices for an item on a specific server
     */
    getItemPrices(itemId, server, callback) {
        this.get(`/market/${server}/items/${itemId}`, {}, callback);
    }

    /**
     * Get the current price history for an item on a specific server
     */
    getItemPriceHistory(itemId, server, callback) {
        this.get(`/market/${server}/items/${itemId}/history`, {}, callback);
    }

    /**
     * Get category stock listing for a specific server
     */
    getCategoryListings(categoryId, server, callback) {
        this.get(`/market/${server}/category/${categoryId}`, {}, callback);
    }

    /**
     * Get Market Board search categories
     */
    getSearchCategories(callback) {
        this.get('/market/categories', {}, callback);
    }

    /**
     * Get a list of servers grouped by their data center
     */
    getServerList(callback) {
        this.get('/servers/dc', {}, callback);
    }
}

export default new XIVAPI;
